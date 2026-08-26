<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'employee_number' => fake()->unique()->numerify('EMP-####'),
            'pin_hash' => null,
            'is_active' => true,
        ];
    }

    public function withPin(string $pin = '4826'): static
    {
        return $this->afterCreating(function (Employee $employee) use ($pin): void {
            $employee->assignPin($pin);
            $employee->save();
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
