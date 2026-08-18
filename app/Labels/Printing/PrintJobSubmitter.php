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
    public function submit(LabelTemplate $template, Printer $printer, User $submitter, ?User $selectedOperator, ?string $pin, int $quantity, array $values): PrintJob
    {
        $operator = $submitter;

        if ($submitter->requires_print_operator_pin) {
            if ($selectedOperator === null || $pin === null || ! $selectedOperator->verifiesPin($pin)) {
                throw new AuthorizationException('The selected user could not be authorized to print.');
            }

            $operator = $selectedOperator;
        }

        $template->loadMissing(['labelStock', 'publishedVersion']);

        if (! $template->is_active || ! $template->labelStock->is_active) {
            throw new LogicException('The selected label template is inactive.');
        }

        if ($template->publishedVersion === null) {
            throw new LogicException('The selected label template has no published version.');
        }

        $printer->loadMissing('labelStock');

        if (! $printer->is_active) {
            throw new LogicException('The selected printer is inactive.');
        }

        if ($printer->label_stock_id === null || ! $printer->labelStock?->is_active) {
            throw new LogicException('The selected printer does not have an active label stock loaded.');
        }

        if ($printer->label_stock_id !== $template->label_stock_id) {
            throw new LogicException('The selected printer is loaded with a different label stock.');
        }

        $job = PrintJob::query()->create([
            'label_template_version_id' => $template->publishedVersion->id,
            'printer_id' => $printer->id,
            'submitted_by' => $submitter->id,
            'input_values' => $values,
            'quantity' => $quantity,
        ]);

        $this->executor->prepareAuthorized($job, $operator);

        return $job->refresh();
    }
}
