<?php

use App\Labels\Enums\PrintJobStatus;
use App\Labels\Examples\CalibrationLabel;
use App\Models\Employee;
use App\Models\LabelStock;
use App\Models\LabelTemplate;
use App\Models\LabelTemplateVersion;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\User;

test('a selected user can authorize and queue a print job', function () {
    $submitter = User::factory()->sharedPrintStation()->create();
    $this->actingAs($submitter);
    [$template, $publishedVersion] = publishedSubmissionTemplate();
    $printer = Printer::factory()->for($template->labelStock)->create(['dpi' => 203]);
    $user = submissionUser('4826');

    $response = $this->postJson('/print/jobs', submissionPayload($template, $printer, $user));

    $response->assertCreated()
        ->assertJson([
            'status' => 'queued',
            'quantity' => 10,
        ])
        ->assertJsonStructure(['job_id', 'job_identifier']);

    $job = PrintJob::query()->findOrFail($response->json('job_id'));

    expect($job->status)->toBe(PrintJobStatus::Queued)
        ->and($job->label_template_version_id)->toBe($publishedVersion->id)
        ->and($job->printer_id)->toBe($printer->id)
        ->and($job->submitted_by)->toBe($submitter->id)
        ->and($job->executed_by)->toBeNull()
        ->and($job->operated_by_employee_id)->toBe($user->id)
        ->and($job->quantity)->toBe(10)
        ->and($job->output_payload)->toStartWith('^XA')
        ->and($job->output_checksum)->toBe(hash('sha256', $job->output_payload));
});

test('submission always uses the latest published template version', function () {
    [$template] = publishedSubmissionTemplate();
    LabelTemplateVersion::factory()->for($template)->published()->create([
        'version' => 2,
        'revision_code' => '0926',
        'definition' => CalibrationLabel::definition(),
    ]);
    LabelTemplateVersion::factory()->for($template)->create([
        'version' => 3,
        'revision_code' => '1026',
        'definition' => CalibrationLabel::definition(),
    ]);
    $printer = Printer::factory()->for($template->labelStock)->create();
    $employee = submissionUser('4826');
    $this->actingAs(User::factory()->create());

    $response = $this->postJson('/print/jobs', submissionPayload($template, $printer, $employee));

    expect(PrintJob::query()->findOrFail($response->json('job_id'))->labelTemplateVersion->version)->toBe(2);
});

test('an incorrect pin does not create a print job', function () {
    $this->actingAs(User::factory()->sharedPrintStation()->create());
    [$template] = publishedSubmissionTemplate();
    $printer = Printer::factory()->for($template->labelStock)->create();
    $user = submissionUser('4826');
    $payload = submissionPayload($template, $printer, $user);
    $payload['pin'] = '1111';

    $this->postJson('/print/jobs', $payload)->assertForbidden();

    expect(PrintJob::query()->count())->toBe(0);
});

test('invalid label values return validation details and retain the failed audit', function () {
    $submitter = User::factory()->sharedPrintStation()->create();
    $this->actingAs($submitter);
    [$template] = publishedSubmissionTemplate();
    $printer = Printer::factory()->for($template->labelStock)->create();
    $user = submissionUser('4826');
    $payload = submissionPayload($template, $printer, $user);
    $payload['values'] = ['country_of_origin' => 'USA'];

    $this->postJson('/print/jobs', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('print_job');

    $job = PrintJob::query()->sole();

    expect($job->status)->toBe(PrintJobStatus::Failed)
        ->and($job->operated_by_employee_id)->toBe($user->id)
        ->and($job->failure_message)->toBe('Field part_number is required.');
});

test('inactive templates and printers cannot receive submissions', function (string $inactive) {
    $this->actingAs(User::factory()->create());
    [$template] = publishedSubmissionTemplate();
    $printer = Printer::factory()->for($template->labelStock)->create();
    $user = submissionUser('4826');

    if ($inactive === 'template') {
        $template->update(['is_active' => false]);
    } else {
        $printer->update(['is_active' => false]);
    }

    $this->postJson('/print/jobs', submissionPayload($template, $printer, $user))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('print_job');

    expect(PrintJob::query()->count())->toBe(0);
})->with(['template', 'printer']);

test('a printer loaded with different stock cannot receive a submission', function () {
    $this->actingAs(User::factory()->create());
    [$template] = publishedSubmissionTemplate();
    $printer = Printer::factory()->create();
    $user = submissionUser('4826');

    $this->postJson('/print/jobs', submissionPayload($template, $printer, $user))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('print_job');

    expect(PrintJob::query()->count())->toBe(0);
});

test('submission input is validated before authorization', function () {
    $this->actingAs(User::factory()->create());
    $this->postJson('/print/jobs', [
        'pin' => 'abc',
        'quantity' => 0,
        'values' => 'invalid',
    ])->assertUnprocessable()->assertJsonValidationErrors([
        'label_template_id',
        'printer_id',
        'pin',
        'quantity',
        'values',
    ]);
});

test('pin attempts are rate limited per selected user and client', function () {
    $this->actingAs(User::factory()->sharedPrintStation()->create());
    [$template] = publishedSubmissionTemplate();
    $printer = Printer::factory()->for($template->labelStock)->create();
    $user = submissionUser('4826');
    $payload = submissionPayload($template, $printer, $user);
    $payload['pin'] = '1111';

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/print/jobs', $payload)->assertForbidden();
    }

    $this->postJson('/print/jobs', $payload)->assertTooManyRequests();
});

test('a personal account prints without selecting an operator or entering a pin', function () {
    [$template] = publishedSubmissionTemplate();
    $printer = Printer::factory()->for($template->labelStock)->create();
    $user = User::factory()->create();
    $employee = submissionUser('4826');
    $this->actingAs($user);
    $payload = submissionPayload($template, $printer, $employee);
    unset($payload['employee_id'], $payload['pin']);

    $response = $this->postJson('/print/jobs', $payload)->assertCreated();
    $job = PrintJob::query()->findOrFail($response->json('job_id'));

    expect($job->submitted_by)->toBe($user->id)
        ->and($job->executed_by)->toBe($user->id);
});

test('guests cannot submit print jobs', function () {
    $this->postJson('/print/jobs', [])->assertUnauthorized();
});

/** @return array{LabelTemplate, LabelTemplateVersion} */
function publishedSubmissionTemplate(): array
{
    $stock = LabelStock::factory()->create([
        'width' => CalibrationLabel::WIDTH_IN_MILLIMETERS,
        'height' => CalibrationLabel::HEIGHT_IN_MILLIMETERS,
    ]);
    $template = LabelTemplate::factory()->for($stock)->create(['code' => 'CMM023']);
    $version = LabelTemplateVersion::factory()->for($template)->published()->create([
        'revision_code' => '0826',
        'definition' => CalibrationLabel::definition(),
    ]);

    return [$template, $version];
}

function submissionUser(string $pin): Employee
{
    $employee = Employee::factory()->create();
    $employee->assignPin($pin);
    $employee->save();

    return $employee;
}

/** @return array<string, mixed> */
function submissionPayload(LabelTemplate $template, Printer $printer, Employee $employee): array
{
    return [
        'label_template_id' => $template->id,
        'printer_id' => $printer->id,
        'employee_id' => $employee->id,
        'pin' => '4826',
        'quantity' => 10,
        'values' => CalibrationLabel::sampleInput(),
    ];
}
