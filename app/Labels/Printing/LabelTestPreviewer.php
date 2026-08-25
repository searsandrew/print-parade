<?php

namespace App\Labels\Printing;

use App\Labels\DataSources\LabelDataSourceResolver;
use App\Labels\Definitions\LabelDefinition;
use App\Labels\Definitions\LabelDefinitionResolver;
use App\Labels\Rendering\LabelRenderContext;
use App\Labels\Rendering\SvgRenderer;
use App\Models\LabelTemplate;
use LogicException;

final readonly class LabelTestPreviewer
{
    public function __construct(
        private LabelDefinitionResolver $definitionResolver,
        private LabelDataSourceResolver $dataSourceResolver,
        private SvgRenderer $renderer,
    ) {}

    /** @param array<string, mixed> $values */
    public function preview(LabelTemplate $template, array $values): LabelTestPreview
    {
        $template->loadMissing(['labelStock', 'publishedVersion']);

        if (! $template->is_active || ! $template->labelStock->is_active) {
            throw new LogicException('The selected label template is inactive.');
        }

        if ($template->publishedVersion === null) {
            throw new LogicException('The selected label template has no published version.');
        }

        return $this->previewDefinition(
            $template,
            $template->publishedVersion->definition,
            $template->publishedVersion->revision_code,
            $values,
        );
    }

    /** @param array<string, mixed> $values */
    public function previewDefinition(LabelTemplate $template, LabelDefinition $definition, string $revisionCode, array $values): LabelTestPreview
    {
        $template->loadMissing('labelStock');

        if (! $template->is_active || ! $template->labelStock->is_active) {
            throw new LogicException('The selected label template is inactive.');
        }

        $inputValues = $this->definitionResolver->resolveInputValues($definition, $values);
        $dataSourceValues = $this->dataSourceResolver->resolve($definition, $inputValues);
        $systemValues = [
            'job_identifier' => sprintf(
                '%s (%s TEST) | PREVIEW',
                $template->code,
                $revisionCode,
            ),
        ];
        $resolvedDefinition = $this->definitionResolver->resolve(
            $definition,
            $inputValues,
            $systemValues,
            $dataSourceValues,
        );

        return new LabelTestPreview(
            svg: $this->renderer->render(
                $resolvedDefinition,
                LabelRenderContext::fromStock($template->labelStock, 203),
            ),
            resolvedValues: [
                ...$inputValues,
                ...$dataSourceValues,
                'system' => $systemValues,
            ],
        );
    }
}
