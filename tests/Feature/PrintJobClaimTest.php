<?php

use App\Labels\Enums\PrintJobStatus;
use App\Models\PrintJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('only one bridge worker can claim a queued print job', function () {
    $job = PrintJob::factory()->create();
    $job->queue(User::factory()->create(), '^XA^FDATOMIC^FS^XZ');
    $firstWorkerView = $job->fresh();
    $secondWorkerView = $job->fresh();

    $firstWorkerView->claim();

    expect($firstWorkerView->status)->toBe(PrintJobStatus::Processing)
        ->and($firstWorkerView->claimed_at)->not->toBeNull();

    expect(fn () => $secondWorkerView->claim())
        ->toThrow(LogicException::class, 'This print job has already been claimed.');

    expect($secondWorkerView->status)->toBe(PrintJobStatus::Processing);
});

test('a pending job cannot be claimed before it is authorized and rendered', function () {
    $job = PrintJob::factory()->create();

    expect(fn () => $job->claim())->toThrow(LogicException::class);
});

test('a queued job with a changed payload cannot be claimed', function () {
    $job = PrintJob::factory()->create();
    $job->queue(User::factory()->create(), '^XA^FDORIGINAL^FS^XZ');

    DB::table('print_jobs')->where('id', $job->id)->update([
        'output_payload' => '^XA^FDTAMPERED^FS^XZ',
    ]);
    $job->refresh();

    expect(fn () => $job->claim())->toThrow(
        LogicException::class,
        'The queued print payload failed its integrity check.',
    );

    expect($job->status)->toBe(PrintJobStatus::Queued);
});

test('only one authorization attempt can queue a pending job', function () {
    $job = PrintJob::factory()->create();
    $firstRequestView = $job->fresh();
    $secondRequestView = $job->fresh();

    $firstRequestView->queue(User::factory()->create(), '^XA^FDFIRST^FS^XZ');

    expect(fn () => $secondRequestView->queue(User::factory()->create(), '^XA^FDSECOND^FS^XZ'))
        ->toThrow(LogicException::class, 'This print job has already been authorized.');

    expect($secondRequestView->output_payload)->toBe('^XA^FDFIRST^FS^XZ');
});
