<?php

namespace App\Labels\Rendering;

use App\Labels\Definitions\LabelDefinitionResolver;
use App\Models\LabelTemplateVersion;

final readonly class LabelPreviewService
{
    public function __construct(
        private LabelDefinitionResolver $resolver,
        private SvgRenderer $renderer,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public function render(LabelTemplateVersion $version, array $values, int $dotsPerInch = 203): string
    {
        $version->loadMissing('labelTemplate.labelStock');

        $resolvedDefinition = $this->resolver->resolve(
            $version->definition,
            $values,
            [
                'job_identifier' => sprintf(
                    '%s (%s) | PREVIEW',
                    $version->labelTemplate->code,
                    $version->revision_code,
                ),
            ],
        );

        return $this->renderer->render(
            $resolvedDefinition,
            LabelRenderContext::fromStock($version->labelTemplate->labelStock, $dotsPerInch),
        );
    }
}
