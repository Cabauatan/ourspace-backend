<?php

namespace App\Events;

use App\Models\Device;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class ChallengesChanged implements ShouldBroadcastNow
{
    public function __construct(private int $coupleId) {}

    public function broadcastOn(): array
    {
        return Device::query()->where('couple_id', $this->coupleId)->whereNull('revoked_at')->get()
            ->map(fn (Device $device) => new PrivateChannel(ChatChanged::channel($device)))->all();
    }

    public function broadcastAs(): string
    {
        return 'challenges.changed';
    }

    public function broadcastWith(): array
    {
        return ['kind' => 'progress'];
    }
}
