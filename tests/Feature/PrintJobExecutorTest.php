<?php

use App\Labels\Enums\PrinterLanguage;
use App\Labels\Enums\PrintJobStatus;
use App\Labels\Examples\CalibrationLabel;
use App\Labels\Printing\PrintJobExecutor;
use App\Models\LabelStock;
use App\Models\LabelTemplate;
use App\Models\LabelTemplateVersion;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

test('the selected user authorizes and prepares a zpl print job', function () {
    $otherUser = printOperatorWithPin('4826');
    $selectedUser = printOperatorWithPin('4826');
    $job = executablePrintJob();

    $prepared = app(PrintJobExecutor::class)->prepare($job, $selectedUser, '4826');

    expect($otherUser->id)->not->toBe($selectedUser->id)
        ->and($job->status)->toBe(PrintJobStatus::Processing)
        ->and($job->executor->is($selectedUser))->toBeTrue()
        ->and($prepared->jobId)->toBe($job->id)
        ->and($prepared->jobIdentifier)->toBe("CMM023 (0826) | {$job->shortIdentifier()}")
        ->and($prepared->printerId)->toBe($job->printer_id)
        ->and($prepared->bridgeIdentifier)->toBe('zebra-packing')
        ->and($prepared->quantity)->toBe(12)
        ->and($prepared->zpl)->toStartWith('^XA')
        ->and($prepared->zpl)->toContain('^PW1200')
        ->and($prepared->zpl)->toContain('PART: ABC-123')
        ->and($prepared->zpl)->toContain($prepared->jobIdentifier)
        ->and($prepared->zpl)->toEndWith('^XZ');
});

test('an incorrect pin does not start the print job', function () {
    $user = printOperatorWithPin('4826');
    $job = executablePrintJob();

    expect(fn () => app(PrintJobExecutor::class)->prepare($job, $user, '1111'))
        ->toThrow(AuthorizationException::class, 'The selected user could not be authorized to print.');

    $job->refresh();

    expect($job->status)->toBe(PrintJobStatus::Pending)
        ->and($job->executed_by)->toBeNull()
        ->and($job->started_at)->toBeNull();
});

test('a selected user without a configured pin cannot authorize printing', function () {
    $user = User::factory()->create();
    $job = executablePrintJob();

    expect(fn () => app(PrintJobExecutor::class)->prepare($job, $user, '4826'))
        ->toThrow(AuthorizationException::class);
});

test('a rendering failure is recorded against the executing user', function () {
    $user = printOperatorWithPin('4826');
    $job = executablePrintJob(['input_values' => []]);

    expect(fn () => app(PrintJobExecutor::class)->prepare($job, $user, '4826'))
        ->toThrow(InvalidArgumentException::class, 'Field part_number is required.');

    $job->refresh();

    expect($job->status)->toBe(PrintJobStatus::Failed)
        ->and($job->executed_by)->toBe($user->id)
        ->and($job->failed_at)->not->toBeNull()
        ->and($job->failure_message)->toBe('Field part_number is required.');
});

test('an inactive printer cannot start a job', function () {
    $user = printOperatorWithPin('4826');
    $printer = Printer::factory()->inactive()->create();
    $job = executablePrintJob([], $printer);

    expect(fn () => app(PrintJobExecutor::class)->prepare($job, $user, '4826'))
        ->toThrow(LogicException::class, 'The selected printer is inactive.');

    expect($job->status)->toBe(PrintJobStatus::Pending);
});

test('unsupported printer languages are recorded as failed attempts', function () {
    $user = printOperatorWithPin('4826');
    $printer = Printer::factory()->create(['language' => PrinterLanguage::Dpl]);
    $job = executablePrintJob([], $printer);

    expect(fn () => app(PrintJobExecutor::class)->prepare($job, $user, '4826'))
        ->toThrow(LogicException::class, 'Printing in dpl is not implemented.');

    expect($job->status)->toBe(PrintJobStatus::Failed)
        ->and($job->failure_message)->toBe('Printing in dpl is not implemented.');
});

function printOperatorWithPin(string $pin): User
{
    $user = User::factory()->create();
    $user->assignPin($pin);
    $user->save();

    return $user;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function executablePrintJob(array $overrides = [], ?Printer $printer = null): PrintJob
{
    $stock = LabelStock::factory()->create([
        'width' => CalibrationLabel::WIDTH_IN_MILLIMETERS,
        'height' => CalibrationLabel::HEIGHT_IN_MILLIMETERS,
    ]);
    $template = LabelTemplate::factory()->for($stock)->create(['code' => 'CMM023']);
    $version = LabelTemplateVersion::factory()->for($template)->create([
        'revision_code' => '0826',
        'definition' => CalibrationLabel::definition(),
    ]);

    $printer ??= Printer::factory()->create([
        'dpi' => 300,
        'bridge_identifier' => 'zebra-packing',
    ]);

    return PrintJob::factory()->for($version)->for($printer)->create([
        'input_values' => CalibrationLabel::sampleInput(),
        'quantity' => 12,
        ...$overrides,
    ]);
}
