<?php

use App\Labels\Enums\PrintJobStatus;
use App\Labels\Printing\QueuedPrintJobClaimer;
use App\Models\PrintBridge;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\User;

test('an unacknowledged expired claim becomes delivery uncertain', function () {
    $bridge = PrintBridge::factory()->create();
    $printer = Printer::factory()->for($bridge)->create();
    $job = PrintJob::factory()->for($printer)->create();
    $job->queue(User::factory()->create(), '^XA^FDLEASE^FS^XZ');

    $claim = app(QueuedPrintJobClaimer::class)->claimNext($bridge);

    expect($claim)->not->toBeNull()
        ->and($claim->job->status)->toBe(PrintJobStatus::Processing)
        ->and($claim->job->lease_expires_at)->not->toBeNull()
        ->and($claim->claimToken)->toHaveLength(64);

    $this->travel(61)->seconds();

    expect(app(QueuedPrintJobClaimer::class)->claimNext($bridge))->toBeNull();

    expect($job->refresh()->status)->toBe(PrintJobStatus::DeliveryUncertain)
        ->and($job->delivery_uncertain_at)->not->toBeNull();
});

test('an expired claim is never offered for automatic redelivery', function () {
    $bridge = PrintBridge::factory()->create();
    $printer = Printer::factory()->for($bridge)->create();
    $job = PrintJob::factory()->for($printer)->create();
    $job->queue(User::factory()->create(), '^XA^FDONCE^FS^XZ');
    app(QueuedPrintJobClaimer::class)->claimNext($bridge);

    $this->travel(2)->minutes();
    app(QueuedPrintJobClaimer::class)->claimNext($bridge);

    expect(app(QueuedPrintJobClaimer::class)->claimNext($bridge))->toBeNull()
        ->and($job->refresh()->status)->toBe(PrintJobStatus::DeliveryUncertain);
});

test('a claim token is stored only as a hash', function () {
    $bridge = PrintBridge::factory()->create();
    $printer = Printer::factory()->for($bridge)->create();
    $job = PrintJob::factory()->for($printer)->create();
    $job->queue(User::factory()->create(), '^XA^FDTOKEN^FS^XZ');

    $claim = app(QueuedPrintJobClaimer::class)->claimNext($bridge);

    expect($claim)->not->toBeNull()
        ->and($claim->job->getRawOriginal('claim_token_hash'))->toBe(hash('sha256', $claim->claimToken))
        ->and($claim->job->toArray())->not->toHaveKey('claim_token_hash');
});
