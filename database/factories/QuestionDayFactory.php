<?php

namespace Database\Factories;

use App\Models\CoupleProfile;
use App\Models\DailyQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuestionDayFactory extends Factory
{
    public function definition(): array
    {
        return ['couple_id' => CoupleProfile::factory(), 'question_id' => DailyQuestion::factory(), 'question' => 'What made you smile today?', 'category' => 'connection', 'question_date' => now(config('tasks.timezone'))->toDateString()];
    }
}
