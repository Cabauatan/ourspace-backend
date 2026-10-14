<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CoupleProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'partner_one_name' => 'Partner One',
            'partner_two_name' => 'Partner Two',
            'setup_code_hash' => hash('sha256', 'test-setup-code'),
            'setup_code_expires_at' => now()->addDay(),
            'recovery_code_hash' => hash('sha256', 'test-recovery-code'),
        ];
    }
}
