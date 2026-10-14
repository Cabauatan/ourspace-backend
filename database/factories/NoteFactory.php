<?php

namespace Database\Factories;

use App\Models\CoupleProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

class NoteFactory extends Factory
{
    public function definition(): array
    {
        return ['couple_id' => CoupleProfile::factory(), 'title' => 'A little thought', 'content' => 'Time together matters.', 'created_by_partner' => 'partner_one', 'visibility' => 'shared', 'pinned' => false];
    }
}
