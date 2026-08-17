<?php

namespace Database\Factories;

use App\Labels\Enums\PrintJobStatus;
use App\Models\LabelTemplateVersion;
use App\Models\PrintJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrintJob>
 */
class PrintJobFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label_template_version_id' => LabelTemplateVersion::factory(),
            'input_values' => [
                'part_number' => fake()->bothify('PART-####'),
            ],
            'quantity' => fake()->numberBetween(1, 100),
            'status' => PrintJobStatus::Pending,
        ];
    }
}
