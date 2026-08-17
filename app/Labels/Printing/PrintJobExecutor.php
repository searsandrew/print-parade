<?php

namespace App\Labels\Printing;

use App\Labels\Definitions\LabelDefinitionResolver;
use App\Labels\Enums\PrinterLanguage;
use App\Labels\Rendering\LabelRenderContext;
use App\Labels\Rendering\ZplRenderer;
use App\Models\PrintJob;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;
use Throwable;

final readonly class PrintJobExecutor
{
    public function __construct(
        private LabelDefinitionResolver $resolver,
        private ZplRenderer $renderer,
    ) {}

    /**
     * Authorize and prepare a pending job for delivery to a Zebra printer bridge.
     *
     * The job remains processing until the bridge confirms or rejects delivery.
     *
     * @throws AuthorizationException
     */
    public function prepare(PrintJob $job, User $user, string $pin): PreparedPrintJob
    {
        if (! $user->verifiesPin($pin)) {
            throw new AuthorizationException('The selected user could not be authorized to print.');
        }

        $job->loadMissing(['labelTemplateVersion.labelTemplate.labelStock', 'printer']);

        if (! $job->printer->is_active) {
            throw new LogicException('The selected printer is inactive.');
        }

        $job->start($user);

        try {
            $version = $job->labelTemplateVersion;

            if ($job->printer->language !== PrinterLanguage::Zpl) {
                throw new LogicException("Printing in {$job->printer->language->value} is not implemented.");
            }

            $jobIdentifier = sprintf(
                '%s (%s) | %s',
                $version->labelTemplate->code,
                $version->revision_code,
                $job->shortIdentifier(),
            );
            $resolvedDefinition = $this->resolver->resolve(
                $version->definition,
                $job->input_values,
                ['job_identifier' => $jobIdentifier],
            );
            $zpl = $this->renderer->render(
                $resolvedDefinition,
                LabelRenderContext::fromStock($version->labelTemplate->labelStock, $job->printer->dpi),
            );

            return new PreparedPrintJob(
                jobId: $job->id,
                jobIdentifier: $jobIdentifier,
                printerId: $job->printer->id,
                bridgeIdentifier: $job->printer->bridge_identifier,
                quantity: $job->quantity,
                zpl: $zpl,
            );
        } catch (Throwable $exception) {
            $job->fail($exception->getMessage());

            throw $exception;
        }
    }
}
