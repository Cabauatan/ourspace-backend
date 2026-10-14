<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $deleted = (bool) $this->deleted_at;

        return [
            'id' => $this->id, 'revision' => $this->revision, 'client_id' => $this->client_id, 'partner_type' => $this->partner_type, 'body' => $deleted ? null : $this->body,
            'created_at' => $this->created_at->toIso8601String(), 'deleted_at' => $this->deleted_at?->toIso8601String(),
            'reply' => $this->reply ? ['id' => $this->reply->id, 'partner_type' => $this->reply->partner_type, 'body' => $this->reply->deleted_at ? null : $this->reply->body, 'deleted' => (bool) $this->reply->deleted_at] : null,
            'media' => $deleted ? [] : $this->chatMedia->map(fn ($media) => ['id' => $media->id, 'media_type' => $media->media_type, 'mime_type' => $media->mime_type, 'file_size' => $media->file_size, 'sort_order' => 0])->all(),
            'reactions' => $deleted ? [] : $this->chatReactions->map(fn ($reaction) => ['partner_type' => $reaction->partner_type, 'emoji' => $reaction->emoji])->all(),
            'reads' => $this->chatReads->map(fn ($read) => ['partner_type' => $read->partner_type, 'read_at' => CarbonImmutable::parse($read->read_at)->toIso8601String()])->all(),
        ];
    }
}
