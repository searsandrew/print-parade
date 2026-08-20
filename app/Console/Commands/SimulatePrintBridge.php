<?php

namespace App\Console\Commands;

use App\Labels\Printing\QueuedPrintJobClaimer;
use App\Models\PrintBridge;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;

#[Signature('print-bridge:simulate
    {bridge? : Active bridge ID or exact name}
    {--complete : Mark the claimed job completed after inspection}
    {--fail= : Mark the claimed job failed with this message}
    {--leave-claimed : Leave the job claimed for manual inspection}')]
#[Description('Claim and inspect one queued print job without sending it to a physical printer')]
class SimulatePrintBridge extends Command
{
    public function handle(QueuedPrintJobClaimer $claimer): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->components->error('The print bridge simulator is only available in local and testing environments.');

            return self::FAILURE;
        }

        if ($this->option('complete') && $this->option('fail') !== null) {
            $this->components->error('Use either --complete or --fail, not both.');

            return self::INVALID;
        }

        $bridge = $this->resolveBridge();

        if ($bridge === null) {
            return self::FAILURE;
        }

        $bridge->forceFill(['last_seen_at' => now()])->save();
        $this->components->info("Simulated heartbeat received from {$bridge->name}.");

        $claim = $claimer->claimNext($bridge);

        if ($claim === null) {
            $this->components->info("No queued jobs are available for {$bridge->name}.");

            return self::SUCCESS;
        }

        $job = $claim->job->loadMissing('printer');
        $payload = $job->output_payload
            ?? throw new RuntimeException('The claimed print job has no output payload.');
        $relativePath = "print-tests/{$job->id}.zpl";

        if (! Storage::disk('local')->put($relativePath, $payload)) {
            throw new RuntimeException("Unable to write {$relativePath}.");
        }

        $this->table(['Property', 'Value'], [
            ['Job', $job->id],
            ['Printer', "{$job->printer->name} ({$job->printer->bridge_identifier})"],
            ['Language', $job->printer->language->value],
            ['Quantity', (string) $job->quantity],
            ['Checksum', (string) $job->output_checksum],
            ['Payload', Storage::disk('local')->path($relativePath)],
        ]);

        $disposition = $this->disposition();

        if ($disposition === 'complete') {
            $job->complete($bridge, $claim->claimToken);
            $this->components->info("Print job {$job->id} marked completed.");
        } elseif ($disposition === 'fail') {
            $message = (string) $this->option('fail');

            if (trim($message) === '') {
                $message = (string) $this->ask('Failure message', 'Simulated bridge failure');
            }

            $job->fail($bridge, $claim->claimToken, $message);
            $this->components->info("Print job {$job->id} marked failed.");
        } else {
            $this->components->warn("Print job {$job->id} remains claimed. Its lease will expire after one minute.");
        }

        return self::SUCCESS;
    }

    private function resolveBridge(): ?PrintBridge
    {
        $bridgeReference = $this->argument('bridge');
        $query = PrintBridge::query()->where('is_active', true)->orderBy('name');

        if (is_string($bridgeReference) && $bridgeReference !== '') {
            $bridge = $query
                ->where(function ($query) use ($bridgeReference): void {
                    $query->where('name', $bridgeReference);

                    if (ctype_digit($bridgeReference)) {
                        $query->orWhere('id', (int) $bridgeReference);
                    }
                })
                ->first();

            if ($bridge === null) {
                $this->components->error("No active print bridge matches {$bridgeReference}.");
            }

            return $bridge;
        }

        $bridges = $query->get();

        if ($bridges->isEmpty()) {
            $this->components->error('No active print bridges are configured.');

            return null;
        }

        if ($bridges->count() === 1) {
            return $bridges->first();
        }

        $choices = $bridges->mapWithKeys(
            fn (PrintBridge $bridge): array => [(string) $bridge->id => "{$bridge->name} (#{$bridge->id})"],
        )->all();
        $selected = $this->choice('Which bridge should claim the next job?', $choices);
        $selectedId = array_search($selected, $choices, true);

        return $bridges->firstWhere('id', (int) $selectedId);
    }

    private function disposition(): string
    {
        if ($this->option('complete')) {
            return 'complete';
        }

        if ($this->option('fail') !== null) {
            return 'fail';
        }

        if ($this->option('leave-claimed') || ! $this->input->isInteractive()) {
            return 'leave-claimed';
        }

        $disposition = $this->choice(
            'What should happen to the simulated job?',
            ['leave-claimed', 'complete', 'fail'],
            'leave-claimed',
        );

        if (! is_string($disposition)) {
            throw new LogicException('The simulated job disposition must be a single choice.');
        }

        return $disposition;
    }
}
