<?php

use App\Labels\Enums\PrintJobStatus;
use App\Models\LabelTemplate;
use App\Models\LabelTemplateVersion;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\User;
use Livewire\Livewire;

test('print job administration requires administrator access', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.print-jobs'))
        ->assertForbidden();

    Livewire::actingAs($user)
        ->test('pages::admin.print-jobs')
        ->assertStatus(403);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.print-jobs'))
        ->assertOk()
        ->assertSee('Print jobs');
});

test('administrators can search the print job audit history', function () {
    $this->actingAs(User::factory()->admin()->create());
    $matchingTemplate = LabelTemplate::factory()->create(['code' => 'CMM023']);
    $otherTemplate = LabelTemplate::factory()->create(['code' => 'CMM999']);
    $matchingVersion = LabelTemplateVersion::factory()->for($matchingTemplate)->create();
    $otherVersion = LabelTemplateVersion::factory()->for($otherTemplate)->create();
    $matchingJob = PrintJob::factory()->for($matchingVersion)->create();
    $otherJob = PrintJob::factory()->for($otherVersion)->create();

    Livewire::test('pages::admin.print-jobs')
        ->set('search', 'CMM023')
        ->assertSee($matchingJob->shortIdentifier())
        ->assertDontSee($otherJob->shortIdentifier());
});

test('administrators can filter jobs by lifecycle and equipment', function () {
    $this->actingAs(User::factory()->admin()->create());
    $printer = Printer::factory()->create(['name' => 'Packing Zebra']);
    $matchingJob = PrintJob::factory()->for($printer)->create([
        'status' => PrintJobStatus::Failed,
        'failed_at' => now(),
        'failure_message' => 'Printer unavailable.',
    ]);
    $otherJob = PrintJob::factory()->create(['status' => PrintJobStatus::Spooled]);

    Livewire::test('pages::admin.print-jobs')
        ->set('status', PrintJobStatus::Failed->value)
        ->set('printer', (string) $printer->id)
        ->assertSee($matchingJob->shortIdentifier())
        ->assertDontSee($otherJob->shortIdentifier());
});

test('job detail exposes audit attribution integrity and failure diagnostics', function () {
    $this->actingAs(User::factory()->admin()->create());
    $submitter = User::factory()->create(['name' => 'Warehouse Scanner']);
    $operator = User::factory()->create(['name' => 'Alex Operator']);
    $job = PrintJob::factory()->for($submitter, 'submitter')->create([
        'status' => PrintJobStatus::Failed,
        'executed_by' => $operator->id,
        'output_payload' => '^XA^FO10,10^FDTEST^FS^XZ',
        'output_checksum' => hash('sha256', '^XA^FO10,10^FDTEST^FS^XZ'),
        'failed_at' => now(),
        'failure_message' => 'Printer is out of media.',
    ]);

    Livewire::test('pages::admin.print-jobs')
        ->call('viewJob', $job->id)
        ->assertSee($job->id)
        ->assertSee('Warehouse Scanner')
        ->assertSee('Alex Operator')
        ->assertSee('Printer is out of media.')
        ->assertSee($job->output_checksum);
});

test('administrators can cancel a pending or queued job', function (PrintJobStatus $status) {
    $this->actingAs(User::factory()->admin()->create());
    $job = PrintJob::factory()->create();

    if ($status === PrintJobStatus::Queued) {
        $job->queue(User::factory()->create(), '^XA^XZ');
    }

    Livewire::test('pages::admin.print-jobs')
        ->call('cancelJob', $job->id)
        ->assertHasNoErrors();

    expect($job->refresh()->status)->toBe(PrintJobStatus::Cancelled)
        ->and($job->cancelled_at)->not->toBeNull();
})->with([
    'pending' => PrintJobStatus::Pending,
    'queued' => PrintJobStatus::Queued,
]);

test('administrators cannot cancel a job that may have reached a printer', function () {
    $this->actingAs(User::factory()->admin()->create());
    $job = PrintJob::factory()->create(['status' => PrintJobStatus::DeliveryUncertain]);

    expect(fn () => Livewire::test('pages::admin.print-jobs')->call('cancelJob', $job->id))
        ->toThrow(LogicException::class);

    expect($job->refresh()->status)->toBe(PrintJobStatus::DeliveryUncertain);
});

test('delivery uncertain details warn against blind reprinting', function () {
    $this->actingAs(User::factory()->admin()->create());
    $job = PrintJob::factory()->create([
        'status' => PrintJobStatus::DeliveryUncertain,
        'delivery_uncertain_at' => now(),
    ]);

    Livewire::test('pages::admin.print-jobs')
        ->call('viewJob', $job->id)
        ->assertSee('Delivery is uncertain')
        ->assertSee('Do not reprint until an operator confirms');
});

test('spooled job details explain that physical printing is unconfirmed', function () {
    $this->actingAs(User::factory()->admin()->create());
    $job = PrintJob::factory()->create([
        'status' => PrintJobStatus::Spooled,
        'spooled_at' => now(),
    ]);

    Livewire::test('pages::admin.print-jobs')
        ->call('viewJob', $job->id)
        ->assertSee('Sent to the Windows printer queue')
        ->assertSee('not that the printer physically produced every label')
        ->assertSee('Sent to printer');
});
