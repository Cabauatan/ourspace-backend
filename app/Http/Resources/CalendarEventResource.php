<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CalendarEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'title' => $this->title, 'description' => $this->description, 'event_type' => $this->event_type,
            'start_date' => $this->start_date->format('Y-m-d'), 'end_date' => $this->end_date->format('Y-m-d'),
            'reminder_at' => $this->reminder_at?->toIso8601String(), 'reminder_local' => $this->reminder_at?->copy()->timezone(config('tasks.timezone'))->format('Y-m-d\TH:i'),
            'reminder_dismissed_at' => $this->reminder_dismissed_at?->toIso8601String(), 'created_by_partner' => $this->created_by_partner,
            'virtual' => false, 'created_at' => $this->created_at->toIso8601String(), 'updated_at' => $this->updated_at->toIso8601String(), 'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
