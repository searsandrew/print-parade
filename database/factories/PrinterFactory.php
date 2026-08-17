<?php

namespace Database\Factories;

use App\Labels\Enums\PrinterLanguage;
use App\Models\Printer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Printer>
 */
class PrinterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Packing Zebra', 'Shipping Zebra', 'Autobagger']),
            'location' => fake()->randomElement(['Packing', 'Shipping', 'Production']),
            'language' => PrinterLanguage::Zpl,
            'dpi' => fake()->randomElement([203, 300]),
            'bridge_identifier' => fake()->bothify('printer-####'),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function dpl(): static
    {
        return $this->state(fn (array $attributes): array => [
            'language' => PrinterLanguage::Dpl,
        ]);
    }

    public function raster(): static
    {
        return $this->state(fn (array $attributes): array => [
            'language' => PrinterLanguage::Raster,
        ]);
    }
}
