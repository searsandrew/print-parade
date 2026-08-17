<?php

use App\Labels\Enums\PrintJobStatus;
use App\Models\PrintBridge;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\User;

test('a bridge token authenticates heartbeat requests', function () {
    $bridge = PrintBridge::factory()->create();
    $token = $bridge->issueToken();

    $this->withToken($token)->postJson('/api/bridge/heartbeat')
        ->assertOk()
        ->assertJson(['status' => 'ok', 'bridge_id' => $bridge->id]);

    expect($bridge->refresh()->last_seen_at)->not->toBeNull();
});

test('invalid and inactive bridge credentials are rejected', function () {
    $this->withToken('invalid')->postJson('/api/bridge/heartbeat')->assertUnauthorized();

    $bridge = PrintBridge::factory()->inactive()->create();
    $token = $bridge->issueToken();

    $this->withToken($token)->postJson('/api/bridge/heartbeat')->assertUnauthorized();
});

test('a bridge claims only jobs assigned to its printers', function () {
    [$bridge, $token] = bridgeWithToken();
    [$otherBridge] = bridgeWithToken();
    $assigned = queuedBridgeJob($bridge, '^XA^FDASSIGNED^FS^XZ');
    $other = queuedBridgeJob($otherBridge, '^XA^FDOTHER^FS^XZ');

    $this->withToken($token)->postJson('/api/bridge/jobs/claim')
        ->assertOk()
        ->assertJsonStructure(['claim_token', 'lease_expires_at'])
        ->assertJson([
            'job_id' => $assigned->id,
            'printer' => $assigned->printer->bridge_identifier,
            'language' => 'zpl',
            'quantity' => $assigned->quantity,
            'payload' => '^XA^FDASSIGNED^FS^XZ',
            'checksum' => hash('sha256', '^XA^FDASSIGNED^FS^XZ'),
        ]);

    expect($assigned->refresh()->status)->toBe(PrintJobStatus::Processing)
        ->and($assigned->claimed_by_bridge)->toBe($bridge->id)
        ->and($other->refresh()->status)->toBe(PrintJobStatus::Queued);
});

test('a bridge acknowledgement requires the matching claim token', function () {
    [$bridge, $token] = bridgeWithToken();
    $job = queuedBridgeJob($bridge);
    $job->claim($bridge);

    $this->withToken($token)->postJson("/api/bridge/jobs/{$job->id}/complete", [
        'claim_token' => 'incorrect',
    ])->assertNotFound();

    expect($job->refresh()->status)->toBe(PrintJobStatus::Processing);
});

test('a bridge receives no content when it has no queued jobs', function () {
    [, $token] = bridgeWithToken();

    $this->withToken($token)->postJson('/api/bridge/jobs/claim')->assertNoContent();
});

test('the claiming bridge can complete or fail its jobs', function () {
    [$bridge, $token] = bridgeWithToken();
    $completed = queuedBridgeJob($bridge);
    $failed = queuedBridgeJob($bridge);
    $completedToken = $completed->claim($bridge);
    $failedToken = $failed->claim($bridge);

    $this->withToken($token)->postJson("/api/bridge/jobs/{$completed->id}/complete", [
        'claim_token' => $completedToken,
    ])
        ->assertOk()
        ->assertJson(['status' => 'completed']);
    $this->withToken($token)->postJson("/api/bridge/jobs/{$failed->id}/fail", [
        'claim_token' => $failedToken,
        'message' => 'Windows spooler rejected the job.',
    ])->assertOk()->assertJson(['status' => 'failed']);

    expect($completed->refresh()->status)->toBe(PrintJobStatus::Completed)
        ->and($failed->refresh()->status)->toBe(PrintJobStatus::Failed)
        ->and($failed->failure_message)->toBe('Windows spooler rejected the job.');
});

test('a different bridge cannot report a claimed job result', function () {
    [$bridge] = bridgeWithToken();
    [, $otherToken] = bridgeWithToken();
    $job = queuedBridgeJob($bridge);
    $claimToken = $job->claim($bridge);

    $this->withToken($otherToken)->postJson("/api/bridge/jobs/{$job->id}/complete", [
        'claim_token' => $claimToken,
    ])
        ->assertNotFound();

    expect($job->refresh()->status)->toBe(PrintJobStatus::Processing);
});

/** @return array{PrintBridge, string} */
function bridgeWithToken(): array
{
    $bridge = PrintBridge::factory()->create();

    return [$bridge, $bridge->issueToken()];
}

function queuedBridgeJob(PrintBridge $bridge, string $payload = '^XA^FDTEST^FS^XZ'): PrintJob
{
    $printer = Printer::factory()->for($bridge)->create();
    $job = PrintJob::factory()->for($printer)->create();
    $job->queue(User::factory()->create(), $payload);

    return $job;
}
