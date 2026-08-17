<?php

namespace App\Labels\Printing;

use App\Labels\Enums\PrintJobStatus;
use App\Models\PrintBridge;
use App\Models\PrintJob;
use Illuminate\Support\Facades\DB;

final class QueuedPrintJobClaimer
{
    public function claimNext(PrintBridge $bridge): ?PrintJob
    {
        return DB::transaction(function () use ($bridge): ?PrintJob {
            $job = PrintJob::query()
                ->where('status', PrintJobStatus::Queued)
                ->whereHas('printer', fn ($query) => $query
                    ->where('print_bridge_id', $bridge->id)
                    ->where('is_active', true))
                ->with('printer')
                ->orderBy('queued_at')
                ->lockForUpdate()
                ->first();

            $job?->claim($bridge);

            return $job;
        });
    }
}
