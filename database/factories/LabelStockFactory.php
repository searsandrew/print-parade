<?php

namespace Database\Factories;

use App\Labels\Enums\LabelMediaType;
use App\Models\LabelStock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabelStock>
 */
class LabelStockFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var array{name: string, width: string, height: string} $stock */
        $stock = fake()->randomElement([
            ['name' => '4 × 2 Thermal Label', 'width' => '101.600', 'height' => '50.800'],
            ['name' => '3 × 1 Thermal Label', 'width' => '76.200', 'height' => '25.400'],
            ['name' => '2 × 1 Thermal Label', 'width' => '50.800', 'height' => '25.400'],
        ]);

        return [
            ...$stock,
            'media_type' => LabelMediaType::Gap,
            'description' => fake()->optional()->sentence(),
            'sku' => fake()->optional()->bothify('LBL-####'),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function continuous(): static
    {
        return $this->state(fn (array $attributes): array => [
            'media_type' => LabelMediaType::Continuous,
        ]);
    }

    public function blackMark(): static
    {
        return $this->state(fn (array $attributes): array => [
            'media_type' => LabelMediaType::BlackMark,
        ]);
    }
}
