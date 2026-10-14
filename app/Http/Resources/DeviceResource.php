<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_name' => $this->device_name,
            'partner_type' => $this->partner_type,
            'is_primary' => $this->is_primary,
            'registered_at' => $this->registered_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'status' => $this->revoked_at ? 'revoked' : 'active',
            'is_current' => $request->user()?->id === $this->id,
        ];
    }
}
