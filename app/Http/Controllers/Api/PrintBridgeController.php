<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Labels\Printing\QueuedPrintJobClaimer;
use App\Models\PrintBridge;
use App\Models\PrintJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrintBridgeController extends Controller
{
    public function heartbeat(Request $request): JsonResponse
    {
        return response()->json(['status' => 'ok', 'bridge_id' => $this->bridge($request)->id]);
    }

    public function claim(Request $request, QueuedPrintJobClaimer $claimer): JsonResponse|Response
    {
        $claim = $claimer->claimNext($this->bridge($request));

        if ($claim === null) {
            return response()->noContent();
        }

        $job = $claim->job;

        return response()->json([
            'job_id' => $job->id,
            'claim_token' => $claim->claimToken,
            'lease_expires_at' => $job->lease_expires_at?->toIso8601String(),
            'printer' => $job->printer->bridge_identifier,
            'language' => $job->printer->language->value,
            'quantity' => $job->quantity,
            'payload' => $job->output_payload,
            'checksum' => $job->output_checksum,
        ]);
    }

    public function spooled(Request $request, PrintJob $printJob): JsonResponse
    {
        $bridge = $this->bridge($request);
        abort_unless($printJob->claimed_by_bridge === $bridge->id, 404);
        $validated = $request->validate(['claim_token' => ['required', 'string']]);
        abort_unless($printJob->matchesClaim($bridge, $validated['claim_token']), 404);
        $printJob->markSpooled($bridge, $validated['claim_token']);

        return response()->json(['status' => 'spooled']);
    }

    public function legacyComplete(Request $request, PrintJob $printJob): JsonResponse
    {
        return $this->spooled($request, $printJob);
    }

    public function fail(Request $request, PrintJob $printJob): JsonResponse
    {
        $bridge = $this->bridge($request);
        abort_unless($printJob->claimed_by_bridge === $bridge->id, 404);
        $validated = $request->validate([
            'claim_token' => ['required', 'string'],
            'message' => ['required', 'string', 'max:2000'],
        ]);
        abort_unless($printJob->matchesClaim($bridge, $validated['claim_token']), 404);
        $printJob->fail($bridge, $validated['claim_token'], $validated['message']);

        return response()->json(['status' => 'failed']);
    }

    private function bridge(Request $request): PrintBridge
    {
        $bridge = $request->attributes->get('print_bridge');

        abort_unless($bridge instanceof PrintBridge, 401);

        return $bridge;
    }
}
