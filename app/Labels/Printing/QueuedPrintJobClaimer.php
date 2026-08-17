<?php

namespace App\Labels\Printing;

use App\Labels\Enums\PrintJobStatus;
use App\Models\PrintBridge;
use App\Models\PrintJob;
use Illuminate\Support\Facades\DB;

final class QueuedPrintJobClaimer
{
    public function claimNext(PrintBridge $bridge): ?ClaimedPrintJob
    {
        return DB::transaction(function () use ($bridge): ?ClaimedPrintJob {
            PrintJob::query()
                ->where('status', PrintJobStatus::Processing)
                ->where('lease_expires_at', '<=', now())
                ->whereHas('printer', fn ($query) => $query->where('print_bridge_id', $bridge->id))
                ->update([
                    'status' => PrintJobStatus::DeliveryUncertain->value,
                    'delivery_uncertain_at' => now(),
                ]);

            $job = PrintJob::query()
                ->where('status', PrintJobStatus::Queued)
                ->whereHas('printer', fn ($query) => $query
                    ->where('print_bridge_id', $bridge->id)
                    ->where('is_active', true))
                ->with('printer')
                ->orderBy('queued_at')
                ->lockForUpdate()
                ->first();

            if ($job === null) {
                return null;
            }

            return new ClaimedPrintJob($job, $job->claim($bridge));
        });
    }
}
