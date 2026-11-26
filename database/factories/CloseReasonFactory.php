<?php

namespace Database\Factories;

use App\Models\CloseReason;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CloseReason>
 */
class CloseReasonFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word().' '.fake()->unique()->numerify('##'),
            'position' => 99,
        ];
    }
}
