<?php

namespace Database\Factories;

use App\Models\LabelTemplate;
use App\Models\LabelTemplateVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabelTemplateVersion>
 */
class LabelTemplateVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label_template_id' => LabelTemplate::factory(),
            'version' => 1,
            'revision_code' => fake()->date('my'),
            'schema_version' => 1,
            'definition' => [
                'elements' => [],
                'fields' => [],
            ],
            'created_by' => User::factory(),
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'published_at' => now(),
        ]);
    }
}
