<?php

namespace App\Labels\Definitions;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class ResolvedLabelDefinition implements Arrayable, JsonSerializable
{
    /**
     * @param  list<array<string, mixed>>  $elements
     * @param  array<string, scalar|null>  $values
     */
    public function __construct(
        private array $elements,
        private array $values,
        private int $canvasRotation = 0,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function elements(): array
    {
        return $this->elements;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function values(): array
    {
        return $this->values;
    }

    public function canvasRotation(): int
    {
        return $this->canvasRotation;
    }

    /**
     * @return array{elements: list<array<string, mixed>>, values: array<string, scalar|null>, canvas_rotation: int}
     */
    public function toArray(): array
    {
        return [
            'elements' => $this->elements,
            'values' => $this->values,
            'canvas_rotation' => $this->canvasRotation,
        ];
    }

    /**
     * @return array{elements: list<array<string, mixed>>, values: array<string, scalar|null>, canvas_rotation: int}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
