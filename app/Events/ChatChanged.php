<?php

namespace App\Events;

use App\Models\Device;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class ChatChanged implements ShouldBroadcastNow
{
    public function __construct(private int $coupleId, private string $kind, private ?int $messageId = null) {}

    public static function channel(Device $device): string
    {
        return 'couple.'.$device->couple_id.'.device.'.$device->id;
    }

    public function broadcastOn(): array
    {
        return Device::query()->where('couple_id', $this->coupleId)->whereNull('revoked_at')->get()->map(fn (Device $device) => new PrivateChannel(self::channel($device)))->all();
    }

    public function broadcastAs(): string
    {
        return 'chat.changed';
    }

    public function broadcastWith(): array
    {
        return ['kind' => $this->kind, 'message_id' => $this->messageId];
    }
}
