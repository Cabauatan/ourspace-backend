<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'title' => $this->title, 'content' => $this->content, 'created_by_partner' => $this->created_by_partner, 'visibility' => $this->visibility, 'pinned' => $this->pinned,
            'created_at' => $this->created_at->toIso8601String(), 'updated_at' => $this->updated_at->toIso8601String(), 'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
