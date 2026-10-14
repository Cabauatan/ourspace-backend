<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CoupleProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'partner_one_name' => $this->partner_one_name,
            'partner_two_name' => $this->partner_two_name,
            'partner_one_avatar' => $this->partner_one_avatar ? '/couple-profile/avatar/partner_one' : null,
            'partner_two_avatar' => $this->partner_two_avatar ? '/couple-profile/avatar/partner_two' : null,
            'avatar_version' => hash('sha256', ($this->partner_one_avatar ?? '').'|'.($this->partner_two_avatar ?? '')),
            'relationship_start_date' => $this->relationship_start_date?->format('Y-m-d'),
            'anniversary_date' => $this->anniversary_date?->format('Y-m-d'),
        ];
    }
}
