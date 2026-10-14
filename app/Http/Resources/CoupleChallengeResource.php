<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CoupleChallengeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'challenge_id' => $this->challenge_id, 'title' => $this->title, 'description' => $this->description, 'category' => $this->category, 'difficulty' => $this->difficulty, 'points' => $this->points, 'status' => $this->status,
            'completed_at' => $this->completed_at?->toIso8601String(), 'completed_by_partner' => $this->completed_by_partner, 'created_at' => $this->created_at->toIso8601String(),
            'media' => $this->proof_photo ? [['id' => $this->id, 'media_type' => 'image', 'mime_type' => $this->proof_mime, 'file_size' => $this->proof_size, 'sort_order' => 0]] : [],
        ];
    }
}
