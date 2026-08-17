<?php

namespace Database\Factories;

use App\Models\PrintBridge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrintBridge>
 */
class PrintBridgeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Print Bridge',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }
}
