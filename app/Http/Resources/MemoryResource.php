<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'created_by_partner' => $this->created_by_partner,
            'title' => $this->title, 'description' => $this->description,
            'memory_date' => $this->memory_date->format('Y-m-d'), 'location_name' => $this->location_name,
            'latitude' => $this->latitude, 'longitude' => $this->longitude, 'favorite' => $this->favorite,
            'tags' => $this->tags->pluck('name')->values()->all(),
            'media' => $this->media->map(fn ($media): array => [
                'id' => $media->id, 'media_type' => $media->media_type, 'mime_type' => $media->mime_type,
                'file_size' => $media->file_size, 'sort_order' => $media->sort_order,
                'url' => '/memories/'.$this->id.'/media/'.$media->id,
            ])->values()->all(),
            'created_at' => $this->created_at->toIso8601String(), 'updated_at' => $this->updated_at->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
