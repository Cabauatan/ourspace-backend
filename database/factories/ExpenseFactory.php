<?php

namespace Database\Factories;

use App\Models\CoupleProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpenseFactory extends Factory
{
    public function definition(): array
    {
        return ['couple_id' => CoupleProfile::factory(), 'description' => 'Dinner together', 'amount' => 12345, 'category' => 'food', 'paid_by' => 'partner_one', 'expense_date' => '2026-10-11', 'notes' => null];
    }
}
