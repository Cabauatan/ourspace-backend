<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BucketListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'title' => $this->title, 'description' => $this->description, 'category' => $this->category, 'priority' => $this->priority,
            'target_date' => $this->target_date?->format('Y-m-d'), 'status' => $this->status, 'completed_at' => $this->completed_at?->toIso8601String(),
            'created_by_partner' => $this->created_by_partner, 'notes' => $this->notes,
            'media' => $this->media->map(fn ($photo): array => ['id' => $photo->id, 'media_type' => 'image', 'mime_type' => $photo->mime_type, 'file_size' => $photo->file_size, 'sort_order' => $photo->sort_order, 'url' => '/bucket-list/'.$this->id.'/media/'.$photo->id])->values()->all(),
            'created_at' => $this->created_at->toIso8601String(), 'updated_at' => $this->updated_at->toIso8601String(), 'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
