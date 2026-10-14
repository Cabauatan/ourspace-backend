<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CoupleProfile;
use App\Models\Device;
use App\Models\Note;
use Illuminate\Support\Facades\DB;

class NoteService
{
    private function lockActor(Device $actor): void
    {
        CoupleProfile::query()->whereKey($actor->couple_id)->lockForUpdate()->firstOrFail();
        abort_unless(Device::query()->whereKey($actor->id)->whereNull('revoked_at')->exists(), 401);
    }

    public function find(Device $actor, int $id): Note
    {
        return Note::query()->visibleTo($actor)->findOrFail($id);
    }

    private function activity(Device $actor, Note $note, string $type): void
    {
        if ($note->visibility === 'shared') {
            ActivityLog::record($actor, $type);
        }
    }

    public function save(Device $actor, array $input, ?int $id = null): Note
    {
        return DB::transaction(function () use ($actor, $input, $id): Note {
            $this->lockActor($actor);
            $note = $id ? $this->find($actor, $id) : new Note;
            $visibility = $input['visibility'] === 'shared' ? 'shared' : $actor->partner_type.'_private';
            if ($id) {
                abort_if($note->visibility !== $visibility, 409, 'Visibility is fixed after saving. Create a separate note to share this content.');
            } else {
                $note->couple_id = $actor->couple_id;
                $note->created_by_partner = $actor->partner_type;
                $note->visibility = $visibility;
            }
            $note->fill(collect($input)->only(['title', 'content', 'pinned'])->all());
            $changed = $note->isDirty();
            $note->save();
            if ($changed) {
                $this->activity($actor, $note, $id ? 'note_updated' : 'note_created');
            }

            return $note;
        });
    }

    public function pin(Device $actor, int $id, bool $pinned): Note
    {
        return DB::transaction(function () use ($actor, $id, $pinned): Note {
            $this->lockActor($actor);
            $note = $this->find($actor, $id);
            if ($note->pinned !== $pinned) {
                $note->update(['pinned' => $pinned]);
                $this->activity($actor, $note, $pinned ? 'note_pinned' : 'note_unpinned');
            }

            return $note;
        });
    }

    public function trash(Device $actor, int $id, bool $restore = false): Note
    {
        return DB::transaction(function () use ($actor, $id, $restore): Note {
            $this->lockActor($actor);
            $query = Note::query()->visibleTo($actor);
            if ($restore) {
                $query->onlyTrashed();
            }
            $note = $query->findOrFail($id);
            if ($restore) {
                $note->restore();
            } else {
                $note->delete();
            }
            $this->activity($actor, $note, $restore ? 'note_restored' : 'note_deleted');

            return $note;
        });
    }
}
