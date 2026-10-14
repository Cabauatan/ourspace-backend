<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class DailyQuestionFactory extends Factory
{
    public function definition(): array
    {
        return ['question' => fake()->unique()->sentence().'?', 'category' => 'connection', 'active' => true];
    }
}
