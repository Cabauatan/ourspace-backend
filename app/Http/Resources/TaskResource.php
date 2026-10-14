<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'description' => $this->description,
            'assigned_to' => $this->assigned_to, 'created_by_partner' => $this->created_by_partner,
            'priority' => $this->priority, 'due_date' => $this->due_date?->format('Y-m-d'), 'status' => $this->status,
            'completed_at' => $this->completed_at?->toIso8601String(), 'is_overdue' => $this->resource->isOverdue(),
            'created_at' => $this->created_at->toIso8601String(), 'updated_at' => $this->updated_at->toIso8601String(), 'deleted_at' => $this->deleted_at?->toIso8601String()];
    }
}
