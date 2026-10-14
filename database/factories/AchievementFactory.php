<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class AchievementFactory extends Factory
{
    public function definition(): array
    {
        return ['key' => fake()->unique()->uuid(), 'title' => 'First step', 'description' => 'Complete your first challenge.', 'badge' => 'heart', 'metric' => 'completed_count', 'threshold' => 1];
    }
}
