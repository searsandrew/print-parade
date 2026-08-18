<?php

namespace App\Labels\Rendering;

use App\Labels\DataSources\LabelDataSourceRegistry;
use App\Labels\Definitions\LabelDefinitionResolver;
use App\Models\LabelTemplateVersion;

final readonly class LabelPreviewService
{
    public function __construct(
        private LabelDefinitionResolver $resolver,
        private SvgRenderer $renderer,
        private LabelDataSourceRegistry $dataSourceRegistry,
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
            $this->sampleDataSourceValues(),
        );

        return $this->renderer->render(
            $resolvedDefinition,
            LabelRenderContext::fromStock($version->labelTemplate->labelStock, $dotsPerInch),
        );
    }

    /** @return array<string, array<string, scalar|null>> */
    private function sampleDataSourceValues(): array
    {
        $values = [];

        foreach ($this->dataSourceRegistry->catalog() as $namespace => $fields) {
            foreach ($fields as $name => $field) {
                $values[$namespace][$name] = $field['sample'] ?? match (true) {
                    ($field['format'] ?? null) === 'upc_a' => '036000291452',
                    $field['type'] === 'number' => 123,
                    $field['type'] === 'boolean' => true,
                    $field['type'] === 'date' => now()->toDateString(),
                    default => 'Sample text',
                };
            }
        }

        return $values;
    }
}
