<?php

namespace Database\Factories;

use App\Models\Challenge;
use App\Models\CoupleProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

class CoupleChallengeFactory extends Factory
{
    public function definition(): array
    {
        return ['couple_id' => CoupleProfile::factory(), 'challenge_id' => Challenge::factory(), 'title' => 'Shared adventure', 'description' => 'A little time together.', 'category' => 'adventure', 'difficulty' => 'easy', 'points' => 20, 'status' => 'in_progress'];
    }
}
