<?php

namespace Database\Factories;

use App\Models\QuestionDay;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuestionAnswerFactory extends Factory
{
    public function definition(): array
    {
        return ['question_day_id' => QuestionDay::factory(), 'couple_id' => fn (array $attributes): int => QuestionDay::findOrFail($attributes['question_day_id'])->couple_id, 'question_id' => fn (array $attributes): int => QuestionDay::findOrFail($attributes['question_day_id'])->question_id, 'partner_type' => 'partner_one', 'answer' => 'A walk together.', 'answer_date' => fn (array $attributes): string => QuestionDay::findOrFail($attributes['question_day_id'])->question_date->toDateString()];
    }
}
