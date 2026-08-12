<?php

namespace Database\Factories;

use App\Models\LabelStock;
use App\Models\LabelTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabelTemplate>
 */
class LabelTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label_stock_id' => LabelStock::factory(),
            'code' => strtoupper(fake()->unique()->bothify('???###')),
            'name' => fake()->unique()->words(3, true),
            'slug' => fake()->unique()->slug(3),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
