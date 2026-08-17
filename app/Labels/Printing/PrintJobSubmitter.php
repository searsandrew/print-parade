<?php

namespace App\Labels\Printing;

use App\Models\LabelTemplate;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

final readonly class PrintJobSubmitter
{
    public function __construct(private PrintJobExecutor $executor) {}

    /** @param array<string, mixed> $values */
    public function submit(LabelTemplate $template, Printer $printer, User $user, string $pin, int $quantity, array $values): PrintJob
    {
        if (! $user->verifiesPin($pin)) {
            throw new AuthorizationException('The selected user could not be authorized to print.');
        }

        $template->loadMissing(['labelStock', 'publishedVersion']);

        if (! $template->is_active || ! $template->labelStock->is_active) {
            throw new LogicException('The selected label template is inactive.');
        }

        if ($template->publishedVersion === null) {
            throw new LogicException('The selected label template has no published version.');
        }

        if (! $printer->is_active) {
            throw new LogicException('The selected printer is inactive.');
        }

        $job = PrintJob::query()->create([
            'label_template_version_id' => $template->publishedVersion->id,
            'printer_id' => $printer->id,
            'input_values' => $values,
            'quantity' => $quantity,
        ]);

        $this->executor->prepare($job, $user, $pin);

        return $job->refresh();
    }
}
