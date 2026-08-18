<?php

namespace Database\Factories;

use App\Models\LabelTemplate;
use App\Models\LabelTemplateDraft;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabelTemplateDraft>
 */
class LabelTemplateDraftFactory extends Factory
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
            'user_id' => User::factory(),
            'revision_code' => now()->format('my'),
            'schema_version' => 2,
            'definition' => [
                'elements' => [],
                'fields' => [],
                'canvas_rotation' => 0,
            ],
        ];
    }
}
