<?php

use App\Labels\Enums\PrintJobStatus;
use App\Models\PrintBridge;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

test('the local bridge simulator claims inspects and completes a queued job', function () {
    Storage::fake('local');
    $bridge = PrintBridge::factory()->create(['name' => 'Packing Bridge']);
    $printer = Printer::factory()->for($bridge)->create([
        'name' => 'Packing Zebra',
        'bridge_identifier' => 'packing-zebra-01',
    ]);
    $job = queuedSimulationJob($printer, '^XA^FO20,20^FDTest^FS^XZ');

    $this->artisan('print-bridge:simulate', [
        'bridge' => (string) $bridge->id,
        '--complete' => true,
    ])
        ->expectsOutputToContain('packing-zebra-01')
        ->expectsOutputToContain($job->id)
        ->assertSuccessful();

    Storage::disk('local')->assertExists("print-tests/{$job->id}.zpl");
    expect(Storage::disk('local')->get("print-tests/{$job->id}.zpl"))->toBe('^XA^FO20,20^FDTest^FS^XZ')
        ->and($job->refresh()->status)->toBe(PrintJobStatus::Completed);
});

test('the local bridge simulator can record a simulated failure', function () {
    Storage::fake('local');
    $bridge = PrintBridge::factory()->create();
    $printer = Printer::factory()->for($bridge)->create();
    $job = queuedSimulationJob($printer, '^XA^XZ');

    $this->artisan('print-bridge:simulate', [
        'bridge' => $bridge->name,
        '--fail' => 'USB printer unavailable',
    ])->assertSuccessful();

    expect($job->refresh())
        ->status->toBe(PrintJobStatus::Failed)
        ->failure_message->toBe('USB printer unavailable');
});

test('the local bridge simulator exits cleanly when no queued job exists', function () {
    Storage::fake('local');
    $bridge = PrintBridge::factory()->create(['name' => 'Idle Bridge']);

    $this->artisan('print-bridge:simulate', ['bridge' => (string) $bridge->id])
        ->expectsOutputToContain('Simulated heartbeat received from Idle Bridge.')
        ->expectsOutputToContain('No queued jobs are available for Idle Bridge.')
        ->assertSuccessful();

    expect($bridge->refresh()->last_seen_at)->not->toBeNull();
});

function queuedSimulationJob(Printer $printer, string $payload): PrintJob
{
    $user = User::factory()->create();
    $job = PrintJob::factory()->for($printer)->create();
    $job->queue($user, $payload, ['part_number' => 'CMM023']);

    return $job;
}
