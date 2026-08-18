<?php

namespace App\Labels\DataSources;

use App\Labels\Definitions\LabelDefinition;
use App\Labels\Values\UpcAValue;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class LabelDataSourceResolver
{
    private const PLACEHOLDER_PATTERN = '/[{][{]\s*([a-z][a-z0-9_]*)(?:\.([a-z][a-z0-9_]*))?\s*[}][}]/';

    public function __construct(private LabelDataSourceRegistry $registry) {}

    /**
     * @param  array<string, scalar|null>  $input
     * @return array<string, array<string, scalar|null>>
     */
    public function resolve(LabelDefinition $definition, array $input): array
    {
        $requested = $this->requestedFields($definition);
        $resolved = [];

        foreach ($requested as $namespace => $fields) {
            if (array_key_exists($namespace, $input)) {
                throw new InvalidArgumentException("The {$namespace} namespace is reserved for its data source.");
            }

            $source = $this->registry->source($namespace);
            $catalog = $source->fields();

            foreach ($fields as $field) {
                if (! isset($catalog[$field])) {
                    throw new InvalidArgumentException("Unsupported label value: {$namespace}.{$field}.");
                }

                $this->validateFieldDeclaration($namespace, $field, $catalog[$field]);

                foreach ($catalog[$field]['required_inputs'] as $requiredInput) {
                    if (! array_key_exists($requiredInput, $input) || $input[$requiredInput] === null || $input[$requiredInput] === '') {
                        throw new InvalidArgumentException("Data source {$namespace}.{$field} requires input {$requiredInput}.");
                    }
                }
            }

            $values = $source->resolve($fields, $input);

            foreach ($fields as $field) {
                if (! array_key_exists($field, $values)) {
                    throw new InvalidArgumentException("Data source value {$namespace}.{$field} is unavailable.");
                }

                if (! is_scalar($values[$field]) && $values[$field] !== null) {
                    throw new InvalidArgumentException("Data source value {$namespace}.{$field} must be scalar or null.");
                }

                $this->validateResolvedValue($namespace, $field, $catalog[$field], $values[$field]);

                if (($catalog[$field]['format'] ?? null) === 'upc_a' && is_string($values[$field]) && $values[$field] !== '') {
                    $values[$field] = UpcAValue::normalize("{$namespace}.{$field}", $values[$field]);
                }
            }

            $resolved[$namespace] = array_intersect_key($values, array_flip($fields));
        }

        return $resolved;
    }

    /** @return array<string, list<string>> */
    private function requestedFields(LabelDefinition $definition): array
    {
        $requested = [];

        foreach ($definition->toArray()['elements'] as $element) {
            if (! isset($element['value']) || ! is_string($element['value'])) {
                continue;
            }

            preg_match_all(self::PLACEHOLDER_PATTERN, $element['value'], $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                if (! isset($match[2]) || $match[2] === '' || $match[1] === 'system') {
                    continue;
                }

                $requested[$match[1]][] = $match[2];
            }
        }

        foreach ($requested as $namespace => $fields) {
            $requested[$namespace] = array_values(array_unique($fields));
        }

        return $requested;
    }

    /** @param array<string, mixed> $field */
    private function validateFieldDeclaration(string $namespace, string $name, array $field): void
    {
        if (! isset($field['label']) || ! is_string($field['label']) || trim($field['label']) === '') {
            throw new InvalidArgumentException("Data source field {$namespace}.{$name} must have a label.");
        }

        if (! isset($field['type']) || ! in_array($field['type'], ['string', 'number', 'boolean', 'date'], true)) {
            throw new InvalidArgumentException("Data source field {$namespace}.{$name} has an unsupported type.");
        }

        if (! isset($field['required']) || ! is_bool($field['required'])) {
            throw new InvalidArgumentException("Data source field {$namespace}.{$name} must declare whether it is required.");
        }

        if (! isset($field['required_inputs']) || ! is_array($field['required_inputs']) || ! array_is_list($field['required_inputs'])) {
            throw new InvalidArgumentException("Data source field {$namespace}.{$name} must declare its required inputs.");
        }

        if (isset($field['format']) && ($field['format'] !== 'upc_a' || $field['type'] !== 'string')) {
            throw new InvalidArgumentException("Data source field {$namespace}.{$name} has an unsupported format for its type.");
        }

        foreach ($field['required_inputs'] as $input) {
            if (! is_string($input) || preg_match('/\A[a-z][a-z0-9_]*\z/', $input) !== 1) {
                throw new InvalidArgumentException("Data source field {$namespace}.{$name} has an invalid required input.");
            }
        }
    }

    /** @param array<string, mixed> $field */
    private function validateResolvedValue(string $namespace, string $name, array $field, mixed $value): void
    {
        if ($value === null || $value === '') {
            if ($field['required']) {
                throw new InvalidArgumentException("Data source value {$namespace}.{$name} is required.");
            }

            return;
        }

        $valid = match ($field['type']) {
            'string' => is_string($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'date' => is_string($value) && $this->isIsoDate($value),
            default => false,
        };

        if (! $valid) {
            throw new InvalidArgumentException("Data source value {$namespace}.{$name} must be a valid {$field['type']} value.");
        }
    }

    private function isIsoDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
