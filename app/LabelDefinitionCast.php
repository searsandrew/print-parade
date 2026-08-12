<?php

namespace App;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;

/**
 * @implements CastsAttributes<LabelDefinition, LabelDefinition|array<string, mixed>>
 */
final class LabelDefinitionCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): LabelDefinition
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('A stored label definition must be JSON.');
        }

        /** @var array<string, mixed> $definition */
        $definition = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        return LabelDefinition::fromArray($definition);
    }

    /**
     * @param  LabelDefinition|array<string, mixed>  $value
     * @param  array<string, mixed>  $attributes
     *
     * @throws JsonException
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $definition = $value instanceof LabelDefinition
            ? $value
            : LabelDefinition::fromArray($value);

        return json_encode($definition, JSON_THROW_ON_ERROR);
    }
}
