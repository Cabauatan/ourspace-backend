<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\BucketListItem;
use App\Models\CoupleProfile;
use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class BucketListService
{
    private function lockActor(Device $actor): void
    {
        CoupleProfile::query()->whereKey($actor->couple_id)->lockForUpdate()->firstOrFail();
        abort_unless(Device::query()->whereKey($actor->id)->whereNull('revoked_at')->exists(), 401);
    }

    public function save(Device $actor, array $input, ?int $id = null): BucketListItem
    {
        $stored = [];
        $removed = [];
        try {
            $item = DB::transaction(function () use ($actor, $input, $id, &$stored, &$removed): BucketListItem {
                $this->lockActor($actor);
                $item = $id ? BucketListItem::query()->where('couple_id', $actor->couple_id)->findOrFail($id) : new BucketListItem;
                if (! $id) {
                    $item->couple_id = $actor->couple_id;
                    $item->created_by_partner = $actor->partner_type;
                    $item->status = 'pending';
                }
                $existing = $id ? $item->media()->get() : collect();
                $removeIds = $input['remove_media_ids'] ?? [];
                $images = $input['images'] ?? [];
                if ($existing->whereIn('id', $removeIds)->count() !== count($removeIds)) {
                    throw ValidationException::withMessages(['remove_media_ids' => ['Only photos attached to this goal can be removed.']]);
                }
                if (count($images) && $item->status !== 'completed') {
                    throw ValidationException::withMessages(['images' => ['Complete this goal before adding completion photos.']]);
                }
                $keep = $existing->whereNotIn('id', $removeIds);
                if ($keep->count() + count($images) > 10) {
                    throw ValidationException::withMessages(['images' => ['A goal can contain up to 10 completion photos.']]);
                }
                $item->fill(array_intersect_key($input, array_flip(['title', 'description', 'category', 'priority', 'target_date', 'notes'])));
                $changed = $item->isDirty() || count($images) || count($removeIds);
                $item->save();
                foreach ($existing->whereIn('id', $removeIds) as $photo) {
                    $removed[] = $photo->file_path;
                    $photo->delete();
                }
                $sort = 0;
                foreach ($keep as $photo) {
                    if ($photo->sort_order !== $sort) {
                        $photo->sort_order = $sort;
                        $photo->save();
                    }
                    $sort++;
                }
                foreach ($images as $image) {
                    $path = $image->store('bucket-list/'.$actor->couple_id.'/'.$item->id, 'local');
                    if (! $path) {
                        throw new RuntimeException('Unable to store completion photo.');
                    }
                    $stored[] = $path;
                    $item->media()->create(['file_path' => $path, 'mime_type' => $image->getMimeType(), 'file_size' => $image->getSize(), 'sort_order' => $sort++]);
                }
                if ($changed) {
                    ActivityLog::record($actor, $id ? 'bucket_list_updated' : 'bucket_list_created');
                }

                return $item->load('media');
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($stored);
            throw $error;
        }
        Storage::disk('local')->delete($removed);

        return $item;
    }

    public function status(Device $actor, int $id, string $status): BucketListItem
    {
        return DB::transaction(function () use ($actor, $id, $status): BucketListItem {
            $this->lockActor($actor);
            $item = BucketListItem::query()->where('couple_id', $actor->couple_id)->findOrFail($id);
            if ($item->status !== $status) {
                $item->status = $status;
                $item->completed_at = $status === 'completed' ? now() : null;
                $item->save();
                ActivityLog::record($actor, $status === 'completed' ? 'bucket_list_completed' : 'bucket_list_reopened');
            }

            return $item->load('media');
        });
    }

    public function trash(Device $actor, int $id, bool $restore = false): BucketListItem
    {
        return DB::transaction(function () use ($actor, $id, $restore): BucketListItem {
            $this->lockActor($actor);
            $query = BucketListItem::query()->where('couple_id', $actor->couple_id);
            if ($restore) {
                $query->onlyTrashed();
            }
            $item = $query->findOrFail($id);
            if ($restore) {
                $item->restore();
            } else {
                $item->delete();
            }
            ActivityLog::record($actor, $restore ? 'bucket_list_restored' : 'bucket_list_deleted');

            return $item->load('media');
        });
    }
}
