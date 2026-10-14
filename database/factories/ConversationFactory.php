<?php

namespace Database\Factories;

use App\Models\CoupleProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return ['couple_id' => CoupleProfile::factory()];
    }
}
