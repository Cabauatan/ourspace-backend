<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CoupleProfile;
use App\Models\Device;
use App\Models\Memory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class MemoryService
{
    private function lockActor(Device $actor): void
    {
        CoupleProfile::query()->whereKey($actor->couple_id)->lockForUpdate()->firstOrFail();
        abort_unless(Device::query()->whereKey($actor->id)->whereNull('revoked_at')->exists(), 401);
    }

    public function save(Device $actor, array $input, ?int $memoryId = null): Memory
    {
        $stored = [];
        $removed = [];
        try {
            $memory = DB::transaction(function () use ($actor, $input, $memoryId, &$stored, &$removed): Memory {
                $this->lockActor($actor);
                $memory = $memoryId ? Memory::query()->where('couple_id', $actor->couple_id)->findOrFail($memoryId) : new Memory;
                if (! $memoryId) {
                    $memory->couple_id = $actor->couple_id;
                    $memory->created_by_partner = $actor->partner_type;
                }
                $existing = $memoryId ? $memory->media()->get() : collect();
                $removeIds = $input['remove_media_ids'] ?? [];
                if ($existing->whereIn('id', $removeIds)->count() !== count($removeIds)) {
                    throw ValidationException::withMessages(['remove_media_ids' => ['Only media attached to this memory can be removed.']]);
                }
                $keep = $existing->whereNotIn('id', $removeIds);
                $images = $input['images'] ?? [];
                if ($keep->where('media_type', 'image')->count() + count($images) > 10) {
                    throw ValidationException::withMessages(['images' => ['A memory can contain up to 10 photos. Remove an existing photo before adding another.']]);
                }
                if ($keep->where('media_type', 'video')->count() + (isset($input['video']) ? 1 : 0) > 1) {
                    throw ValidationException::withMessages(['video' => ['A memory can contain one video. Remove the existing video before replacing it.']]);
                }
                $memory->fill(array_intersect_key($input, array_flip(['title', 'description', 'memory_date', 'location_name', 'favorite', 'latitude', 'longitude'])));
                $changed = ! $memoryId || $memory->isDirty() || count($images) || isset($input['video']) || count($removeIds);
                $memory->save();
                if (array_key_exists('tags', $input)) {
                    $oldTags = $memory->tags()->pluck('name')->sort()->values()->all();
                    $newTags = collect($input['tags'])->sort()->values()->all();
                    if ($oldTags !== $newTags) {
                        $memory->tags()->delete();
                        foreach ($newTags as $tag) {
                            $memory->tags()->create(['name' => $tag]);
                        }
                        $changed = true;
                    }
                }
                foreach ($existing->whereIn('id', $removeIds) as $media) {
                    $removed[] = $media->file_path;
                    $media->delete();
                }
                $sort = 0;
                foreach ($keep as $media) {
                    if ($media->sort_order !== $sort) {
                        $media->sort_order = $sort;
                        $media->save();
                    }
                    $sort++;
                }
                $uploads = array_map(fn ($file) => ['file' => $file, 'type' => 'image'], $images);
                if (isset($input['video'])) {
                    $uploads[] = ['file' => $input['video'], 'type' => 'video'];
                }
                foreach ($uploads as $upload) {
                    $path = $upload['file']->store('memories/'.$actor->couple_id.'/'.$memory->id, 'local');
                    if (! $path) {
                        throw new RuntimeException('Unable to store memory media.');
                    }
                    $stored[] = $path;
                    $memory->media()->create(['file_path' => $path, 'media_type' => $upload['type'], 'mime_type' => $upload['file']->getMimeType(), 'file_size' => $upload['file']->getSize(), 'sort_order' => $sort++]);
                }
                if ($changed) {
                    ActivityLog::record($actor, $memoryId ? 'memory_updated' : 'memory_created');
                }
                if (! $memoryId) {
                    app(NotificationService::class)->other($actor, 'memory_created', (string) $memory->id);
                }

                return $memory->load('media', 'tags');
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($stored);
            throw $error;
        }
        Storage::disk('local')->delete($removed);

        return $memory;
    }

    public function favorite(Device $actor, int $id, bool $favorite): Memory
    {
        return DB::transaction(function () use ($actor, $id, $favorite): Memory {
            $this->lockActor($actor);
            $memory = Memory::query()->where('couple_id', $actor->couple_id)->findOrFail($id);
            if ($memory->favorite !== $favorite) {
                $memory->favorite = $favorite;
                $memory->save();
                ActivityLog::record($actor, 'memory_favorite_updated');
            }

            return $memory->load('media', 'tags');
        });
    }

    public function delete(Device $actor, int $id): void
    {
        DB::transaction(function () use ($actor, $id): void {
            $this->lockActor($actor);
            $memory = Memory::query()->where('couple_id', $actor->couple_id)->findOrFail($id);
            $memory->delete();
            ActivityLog::record($actor, 'memory_deleted');
        });
    }

    public function restore(Device $actor, int $id): Memory
    {
        return DB::transaction(function () use ($actor, $id): Memory {
            $this->lockActor($actor);
            $memory = Memory::onlyTrashed()->where('couple_id', $actor->couple_id)->findOrFail($id);
            $memory->restore();
            ActivityLog::record($actor, 'memory_restored');

            return $memory->load('media', 'tags');
        });
    }
}
