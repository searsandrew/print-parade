<?php

use App\Labels\Enums\PrintJobStatus;
use App\Models\LabelTemplateVersion;
use App\Models\PrintJob;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

test('a print job preserves its template version inputs and quantity', function () {
    $version = LabelTemplateVersion::factory()->create();
    $job = PrintJob::factory()->for($version)->create([
        'input_values' => [
            'part_number' => 'ABC-123',
            'upc' => '036000291452',
        ],
        'quantity' => 25,
    ]);

    expect(Str::isUlid($job->id))->toBeTrue()
        ->and($job->shortIdentifier())->toBe(strtoupper(substr($job->id, -8)))
        ->and($job->status)->toBe(PrintJobStatus::Pending)
        ->and($job->input_values)->toBe([
            'part_number' => 'ABC-123',
            'upc' => '036000291452',
        ])
        ->and($job->quantity)->toBe(25)
        ->and($job->labelTemplateVersion->is($version))->toBeTrue()
        ->and($job->printer)->not->toBeNull();
});

test('a pending print job can be queued claimed and marked spooled', function () {
    $user = User::factory()->create();
    $job = PrintJob::factory()->create();

    $job->queue($user, '^XA^FDTEST^FS^XZ');
    $bridge = $job->printer->printBridge;

    expect($job->status)->toBe(PrintJobStatus::Queued)
        ->and($job->executor->is($user))->toBeTrue()
        ->and($job->queued_at)->not->toBeNull()
        ->and($job->output_payload)->toBe('^XA^FDTEST^FS^XZ')
        ->and($job->output_checksum)->toBe(hash('sha256', '^XA^FDTEST^FS^XZ'));

    $claimToken = $job->claim($bridge);

    expect($job->status)->toBe(PrintJobStatus::Processing)
        ->and($job->claimed_at)->not->toBeNull()
        ->and($job->started_at)->not->toBeNull();

    $job->markSpooled($bridge, $claimToken);

    expect($job->status)->toBe(PrintJobStatus::Spooled)
        ->and($job->spooled_at)->not->toBeNull();
});

test('a processing print job can fail with an audit message', function () {
    $job = PrintJob::factory()->create();
    $job->queue(User::factory()->create(), '^XA^XZ');
    $bridge = $job->printer->printBridge;
    $claimToken = $job->claim($bridge);

    $job->fail($bridge, $claimToken, 'Printer bridge did not respond.');

    expect($job->status)->toBe(PrintJobStatus::Failed)
        ->and($job->failed_at)->not->toBeNull()
        ->and($job->failure_message)->toBe('Printer bridge did not respond.');
});

test('a pending print job can be cancelled', function () {
    $job = PrintJob::factory()->create();

    $job->cancel();

    expect($job->status)->toBe(PrintJobStatus::Cancelled)
        ->and($job->cancelled_at)->not->toBeNull();
});

test('a queued print job can be cancelled before a bridge claims it', function () {
    $job = PrintJob::factory()->create();
    $job->queue(User::factory()->create(), '^XA^XZ');

    $job->cancel();

    expect($job->status)->toBe(PrintJobStatus::Cancelled)
        ->and($job->cancelled_at)->not->toBeNull();
});

test('a stale queued view cannot cancel a job after a bridge claims it', function () {
    $job = PrintJob::factory()->create();
    $job->queue(User::factory()->create(), '^XA^XZ');
    $staleView = PrintJob::query()->findOrFail($job->id);

    $job->claim($job->printer->printBridge);

    expect(fn () => $staleView->cancel())->toThrow(
        LogicException::class,
        'This print job can no longer be cancelled.',
    );

    expect($staleView->status)->toBe(PrintJobStatus::Processing);
});

test('print job lifecycle rejects invalid transitions', function () {
    $user = User::factory()->create();
    $spooledJob = PrintJob::factory()->create();
    $spooledJob->queue($user, '^XA^XZ');
    $bridge = $spooledJob->printer->printBridge;
    $claimToken = $spooledJob->claim($bridge);
    $spooledJob->markSpooled($bridge, $claimToken);

    expect(fn () => $spooledJob->queue($user, '^XA^XZ'))->toThrow(LogicException::class)
        ->and(fn () => $spooledJob->cancel())->toThrow(LogicException::class);

    $pendingJob = PrintJob::factory()->create();

    expect(fn () => $pendingJob->markSpooled($pendingJob->printer->printBridge, 'invalid'))->toThrow(LogicException::class)
        ->and(fn () => $pendingJob->fail($pendingJob->printer->printBridge, 'invalid', 'No printer.'))->toThrow(LogicException::class);
});

test('a failed print job requires a failure message', function () {
    $job = PrintJob::factory()->create();
    $job->queue(User::factory()->create(), '^XA^XZ');
    $bridge = $job->printer->printBridge;
    $claimToken = $job->claim($bridge);

    expect(fn () => $job->fail($bridge, $claimToken, '  '))->toThrow(
        LogicException::class,
        'A failed print job must include a failure message.',
    );
});

test('a print job quantity must be positive', function () {
    expect(fn () => PrintJob::factory()->create(['quantity' => 0]))->toThrow(
        LogicException::class,
        'A print job quantity must be at least one.',
    );
});

test('a template version cannot be deleted after it is used by a print job', function () {
    $job = PrintJob::factory()->create();

    expect(fn () => $job->labelTemplateVersion->delete())->toThrow(QueryException::class);
});

test('a printer cannot be deleted after it is used by a print job', function () {
    $job = PrintJob::factory()->create();

    expect(fn () => $job->printer->delete())->toThrow(QueryException::class);
});
