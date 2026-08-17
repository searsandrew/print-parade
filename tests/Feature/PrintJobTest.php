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
        ->and($job->labelTemplateVersion->is($version))->toBeTrue();
});

test('a pending print job can be executed and completed', function () {
    $user = User::factory()->create();
    $job = PrintJob::factory()->create();

    $job->start($user);

    expect($job->status)->toBe(PrintJobStatus::Processing)
        ->and($job->executor->is($user))->toBeTrue()
        ->and($job->started_at)->not->toBeNull();

    $job->complete();

    expect($job->status)->toBe(PrintJobStatus::Completed)
        ->and($job->completed_at)->not->toBeNull();
});

test('a processing print job can fail with an audit message', function () {
    $job = PrintJob::factory()->create();
    $job->start(User::factory()->create());

    $job->fail('Printer bridge did not respond.');

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

test('print job lifecycle rejects invalid transitions', function () {
    $user = User::factory()->create();
    $completedJob = PrintJob::factory()->create();
    $completedJob->start($user);
    $completedJob->complete();

    expect(fn () => $completedJob->start($user))->toThrow(LogicException::class)
        ->and(fn () => $completedJob->cancel())->toThrow(LogicException::class);

    $pendingJob = PrintJob::factory()->create();

    expect(fn () => $pendingJob->complete())->toThrow(LogicException::class)
        ->and(fn () => $pendingJob->fail('No printer.'))->toThrow(LogicException::class);
});

test('a failed print job requires a failure message', function () {
    $job = PrintJob::factory()->create();
    $job->start(User::factory()->create());

    expect(fn () => $job->fail('  '))->toThrow(
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
