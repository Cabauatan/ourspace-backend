<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ChallengeFactory extends Factory
{
    public function definition(): array
    {
        return ['key' => fake()->unique()->uuid(), 'title' => fake()->sentence(3), 'description' => fake()->sentence(), 'category' => 'quality_time', 'difficulty' => 'easy', 'points' => 20, 'active' => true];
    }
}
