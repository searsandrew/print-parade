<?php

namespace App\Labels\Definitions;

use DateTimeImmutable;
use InvalidArgumentException;

final class LabelDefinitionResolver
{
    private const PLACEHOLDER_PATTERN = '/[{][{]\s*([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)?)\s*[}][}]/';

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $system
     */
    public function resolve(LabelDefinition $definition, array $input, array $system): ResolvedLabelDefinition
    {
        $rawDefinition = $definition->toArray();
        $values = $this->resolveInputValues($rawDefinition['fields'], $input);
        $systemValues = $this->normalizeSystemValues($system);
        $elements = [];

        foreach ($rawDefinition['elements'] as $element) {
            $source = $element['type'] === 'job_identifier'
                ? '{{ system.job_identifier }}'
                : ($element['value'] ?? null);

            if (! is_string($source)) {
                $elements[] = $element;

                continue;
            }

            $references = $this->references($source);
            $referencedValues = [];

            foreach ($references as $reference) {
                $referencedValues[$reference] = $this->valueForReference($reference, $values, $systemValues);
            }

            $hideWhenEmpty = $element['hide_when_empty'] ?? true;

            if ($hideWhenEmpty && $references !== [] && $this->allValuesEmpty($referencedValues)) {
                continue;
            }

            $element['value'] = preg_replace_callback(
                self::PLACEHOLDER_PATTERN,
                fn (array $matches): string => $this->stringify($referencedValues[$matches[1]]),
                $source,
            );
            $element['hide_when_empty'] = $hideWhenEmpty;
            $elements[] = $element;
        }

        return new ResolvedLabelDefinition($elements, $values, $rawDefinition['canvas_rotation'] ?? 0);
    }

    /**
     * Validate a value against one field declaration.
     *
     * @param  array<string, mixed>  $field
     */
    public static function validateFieldValue(string $name, array $field, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $valid = match ($field['type']) {
            'string' => is_string($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'date' => is_string($value) && self::isIsoDate($value),
            default => false,
        };

        if (! $valid) {
            throw new InvalidArgumentException("Field {$name} must be a valid {$field['type']} value.");
        }

        if (($field['format'] ?? null) === 'upc_a' && preg_match('/\A\d{11,12}\z/', $value) !== 1) {
            throw new InvalidArgumentException("Field {$name} must contain 11 or 12 UPC-A digits.");
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $input
     * @return array<string, scalar|null>
     */
    private function resolveInputValues(array $fields, array $input): array
    {
        $unknownFields = array_diff(array_keys($input), array_keys($fields));

        if ($unknownFields !== []) {
            throw new InvalidArgumentException('Unknown label input: '.implode(', ', $unknownFields).'.');
        }

        $values = [];

        foreach ($fields as $name => $field) {
            $value = array_key_exists($name, $input)
                ? $input[$name]
                : ($field['default'] ?? null);

            self::validateFieldValue($name, $field, $value);

            if ($field['required'] && ($value === null || $value === '')) {
                throw new InvalidArgumentException("Field {$name} is required.");
            }

            if (($field['format'] ?? null) === 'upc_a' && is_string($value) && $value !== '') {
                $value = $this->normalizeUpcA($name, $value);
            }

            /** @var scalar|null $value */
            $values[$name] = $value;
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $system
     * @return array<string, scalar|null>
     */
    private function normalizeSystemValues(array $system): array
    {
        $values = [];

        foreach ($system as $name => $value) {
            if (preg_match('/\A[a-z][a-z0-9_]*\z/', $name) !== 1) {
                throw new InvalidArgumentException('System value names must use snake_case.');
            }

            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException("System value {$name} must be scalar or null.");
            }

            $values[$name] = $value;
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private function references(string $source): array
    {
        preg_match_all(self::PLACEHOLDER_PATTERN, $source, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @param  array<string, scalar|null>  $values
     * @param  array<string, scalar|null>  $systemValues
     */
    private function valueForReference(string $reference, array $values, array $systemValues): string|int|float|bool|null
    {
        if (str_contains($reference, '.')) {
            [$namespace, $name] = explode('.', $reference, 2);

            if ($namespace !== 'system') {
                throw new InvalidArgumentException("Unsupported label value namespace: {$namespace}.");
            }

            if (! array_key_exists($name, $systemValues)) {
                throw new InvalidArgumentException("System value {$name} is unavailable.");
            }

            return $systemValues[$name];
        }

        if (! array_key_exists($reference, $values)) {
            throw new InvalidArgumentException("Unknown label field: {$reference}.");
        }

        return $values[$reference];
    }

    /**
     * @param  array<string, scalar|null>  $values
     */
    private function allValuesEmpty(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    private function stringify(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === null => '',
            $value === true => 'true',
            $value === false => 'false',
            default => (string) $value,
        };
    }

    private function normalizeUpcA(string $name, string $value): string
    {
        $data = substr($value, 0, 11);
        $checkDigit = $this->upcACheckDigit($data);

        if (strlen($value) === 12 && $value[11] !== $checkDigit) {
            throw new InvalidArgumentException("Field {$name} has an invalid UPC-A check digit.");
        }

        return $data.$checkDigit;
    }

    private function upcACheckDigit(string $data): string
    {
        $sum = 0;

        for ($index = 0; $index < 11; $index++) {
            $digit = (int) $data[$index];
            $sum += $index % 2 === 0 ? $digit * 3 : $digit;
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }

    private static function isIsoDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
