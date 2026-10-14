<?php

namespace Database\Factories;

use App\Models\CoupleProfile;
use App\Models\Device;
use App\Models\PartnerCall;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PartnerCall>
 */
class PartnerCallFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'client_id' => (string) Str::uuid(),
            'couple_id' => CoupleProfile::factory(),
            'caller_device_id' => fn (array $attributes) => Device::factory()->create(['couple_id' => $attributes['couple_id'], 'partner_type' => 'partner_one'])->id,
            'callee_device_id' => fn (array $attributes) => Device::factory()->create(['couple_id' => $attributes['couple_id'], 'partner_type' => 'partner_two'])->id,
            'caller_partner' => 'partner_one',
            'kind' => 'voice',
            'status' => 'ringing',
            'caller_seen_at' => now(),
        ];
    }
}
