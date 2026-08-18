<?php

namespace App\Labels\DataSources;

interface LabelDataSource
{
    public function namespace(): string;

    /**
     * Fields exposed to label templates, keyed by their unqualified field name.
     *
     * @return array<string, array{label: string, type: 'string'|'number'|'boolean'|'date', required: bool, required_inputs: list<string>, format?: 'upc_a', sample?: scalar|null}>
     */
    public function fields(): array;

    /**
     * Resolve only the requested fields using validated operator input.
     *
     * @param  list<string>  $fields
     * @param  array<string, scalar|null>  $input
     * @return array<string, scalar|null>
     */
    public function resolve(array $fields, array $input): array;
}
