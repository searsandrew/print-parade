<?php

namespace App\Labels\DataSources;

use InvalidArgumentException;

final readonly class LabelDataSourceRegistry
{
    /**
     * @var array<string, LabelDataSource>
     */
    private array $sources;

    /** @param iterable<LabelDataSource> $sources */
    public function __construct(iterable $sources = [])
    {
        $indexed = [];

        foreach ($sources as $source) {
            $namespace = $source->namespace();

            if (preg_match('/\A[a-z][a-z0-9_]*\z/', $namespace) !== 1 || $namespace === 'system') {
                throw new InvalidArgumentException("Invalid label data source namespace: {$namespace}.");
            }

            if (isset($indexed[$namespace])) {
                throw new InvalidArgumentException("Duplicate label data source namespace: {$namespace}.");
            }

            $indexed[$namespace] = $source;
        }

        $this->sources = $indexed;
    }

    public function source(string $namespace): LabelDataSource
    {
        return $this->sources[$namespace]
            ?? throw new InvalidArgumentException("Unsupported label value namespace: {$namespace}.");
    }

    /**
     * @return array<string, array<string, array{label: string, type: 'string'|'number'|'boolean'|'date', required: bool, required_inputs: list<string>, format?: 'upc_a', sample?: scalar|null}>>
     */
    public function catalog(): array
    {
        $catalog = [];

        foreach ($this->sources as $namespace => $source) {
            $catalog[$namespace] = $source->fields();
        }

        return $catalog;
    }
}
