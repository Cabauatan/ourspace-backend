<?php

namespace Database\Factories;

use App\Models\CoupleProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'couple_id' => CoupleProfile::factory(),
            'partner_type' => 'partner_one',
            'device_name' => 'Test phone',
            'device_identifier' => fake()->uuid(),
            'token_hash' => hash('sha256', random_bytes(48)),
            'is_primary' => true,
            'registered_at' => now(),
        ];
    }
}
