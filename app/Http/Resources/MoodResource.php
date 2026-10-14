<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MoodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'partner_type' => $this->partner_type, 'mood' => $this->mood, 'note' => $this->note, 'mood_date' => $this->mood_date->toDateString(), 'updated_at' => $this->updated_at->toIso8601String()];
    }
}
