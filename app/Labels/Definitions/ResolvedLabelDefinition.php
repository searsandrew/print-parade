<?php

namespace App\Labels\Definitions;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class ResolvedLabelDefinition implements Arrayable, JsonSerializable
{
    /** @var 0|90|180|270 */
    private int $canvasRotation;

    /**
     * @param  list<array<string, mixed>>  $elements
     * @param  array<string, scalar|null>  $values
     */
    public function __construct(
        private array $elements,
        private array $values,
        int $canvasRotation = 0,
    ) {
        $this->canvasRotation = match ($canvasRotation) {
            0 => 0,
            90 => 90,
            180 => 180,
            270 => 270,
            default => throw new InvalidArgumentException('A resolved label canvas must use a supported rotation.'),
        };
    }

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

    /** @return 0|90|180|270 */
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
