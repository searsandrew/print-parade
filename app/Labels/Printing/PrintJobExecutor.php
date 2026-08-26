<?php

namespace App\Labels\Printing;

use App\Labels\DataSources\LabelDataSourceResolver;
use App\Labels\Definitions\LabelDefinitionResolver;
use App\Labels\Enums\PrinterLanguage;
use App\Labels\Enums\PrintJobStatus;
use App\Labels\Rendering\LabelRenderContext;
use App\Labels\Rendering\ZplRenderer;
use App\Models\Employee;
use App\Models\PrintJob;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;
use Throwable;

final readonly class PrintJobExecutor
{
    public function __construct(
        private LabelDefinitionResolver $resolver,
        private LabelDataSourceResolver $dataSourceResolver,
        private ZplRenderer $renderer,
    ) {}

    /**
     * Authorize, render, and queue a pending job for a Zebra printer bridge.
     *
     * @throws AuthorizationException
     */
    public function prepare(PrintJob $job, Employee $employee, string $pin): PreparedPrintJob
    {
        if (! $employee->is_active || ! $employee->verifiesPin($pin)) {
            throw new AuthorizationException('The selected employee could not be authorized to print.');
        }

        return $this->prepareAuthorized($job, $employee);
    }

    public function prepareAuthorized(PrintJob $job, User|Employee $operator): PreparedPrintJob
    {
        $job->loadMissing(['labelTemplateVersion.labelTemplate.labelStock', 'printer']);

        if (! $job->printer->is_active) {
            throw new LogicException('The selected printer is inactive.');
        }

        try {
            $version = $job->labelTemplateVersion;
            $definition = $job->definition_snapshot ?? $version->definition;
            $revisionCode = $job->revision_code_snapshot ?? $version->revision_code;

            if ($job->printer->language !== PrinterLanguage::Zpl) {
                throw new LogicException("Printing in {$job->printer->language->value} is not implemented.");
            }

            $jobIdentifier = sprintf(
                '%s (%s%s) | %s',
                $version->labelTemplate->code,
                $revisionCode,
                $job->is_test ? ' TEST' : '',
                $job->shortIdentifier(),
            );
            $inputValues = $this->resolver->resolveInputValues($definition, $job->input_values);
            $dataSourceValues = $this->dataSourceResolver->resolve($definition, $inputValues);
            $systemValues = ['job_identifier' => $jobIdentifier];
            $resolvedDefinition = $this->resolver->resolve(
                $definition,
                $inputValues,
                $systemValues,
                $dataSourceValues,
            );
            $zpl = $this->renderer->render(
                $resolvedDefinition,
                LabelRenderContext::fromStock($version->labelTemplate->labelStock, $job->printer->dpi),
            );
            $job->queue($operator, $zpl, [
                ...$inputValues,
                ...$dataSourceValues,
                'system' => $systemValues,
            ]);

            return new PreparedPrintJob(
                jobId: $job->id,
                jobIdentifier: $jobIdentifier,
                printerId: $job->printer->id,
                bridgeIdentifier: $job->printer->bridge_identifier,
                quantity: $job->quantity,
                zpl: $zpl,
            );
        } catch (Throwable $exception) {
            if ($job->status === PrintJobStatus::Pending) {
                $job->failPreparation($operator, $exception->getMessage());
            }

            throw $exception;
        }
    }
}
