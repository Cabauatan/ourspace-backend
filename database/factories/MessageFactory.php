<?php

namespace Database\Factories;

use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MessageFactory extends Factory
{
    public function definition(): array
    {
        return ['conversation_id' => Conversation::factory(), 'partner_type' => 'partner_one', 'body' => fake()->sentence(), 'client_id' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'fixture')];
    }
}
