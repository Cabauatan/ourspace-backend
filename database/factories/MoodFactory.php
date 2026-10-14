<?php

namespace Database\Factories;

use App\Models\CoupleProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

class MoodFactory extends Factory
{
    public function definition(): array
    {
        return ['couple_id' => CoupleProfile::factory(), 'partner_type' => 'partner_one', 'mood' => 'happy', 'note' => null, 'mood_date' => now(config('tasks.timezone'))->toDateString()];
    }
}
