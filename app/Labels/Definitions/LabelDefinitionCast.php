<?php

namespace App\Labels\Definitions;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;

/**
 * @implements CastsAttributes<LabelDefinition|null, LabelDefinition|array<string, mixed>|null>
 */
final class LabelDefinitionCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?LabelDefinition
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('A stored label definition must be JSON.');
        }

        /** @var array<string, mixed> $definition */
        $definition = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        return LabelDefinition::fromArray($definition);
    }

    /**
     * @param  LabelDefinition|array<string, mixed>|null  $value
     * @param  array<string, mixed>  $attributes
     *
     * @throws JsonException
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $definition = $value instanceof LabelDefinition
            ? $value
            : LabelDefinition::fromArray($value);

        return json_encode($definition, JSON_THROW_ON_ERROR);
    }
}
