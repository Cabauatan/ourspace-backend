<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CoupleProfile;
use App\Models\Device;
use App\Models\SharedTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TaskService
{
    private function lockActor(Device $actor): void
    {
        CoupleProfile::query()->whereKey($actor->couple_id)->lockForUpdate()->firstOrFail();
        abort_unless(Device::query()->whereKey($actor->id)->whereNull('revoked_at')->exists(), 401);
    }

    public function save(Device $actor, array $input, ?int $id = null): SharedTask
    {
        return DB::transaction(function () use ($actor, $input, $id): SharedTask {
            $this->lockActor($actor);
            $task = $id ? SharedTask::query()->where('couple_id', $actor->couple_id)->findOrFail($id) : new SharedTask;
            if (! $id) {
                $task->couple_id = $actor->couple_id;
                $task->created_by_partner = $actor->partner_type;
                $task->status = 'pending';
            }
            $task->fill($input);
            $assignmentChanged = $task->isDirty('assigned_to');
            $changed = $task->isDirty();
            $task->save();
            if ($changed) {
                ActivityLog::record($actor, $id ? 'task_updated' : 'task_created');
            }
            if ($assignmentChanged && ($task->assigned_to === 'both' || $task->assigned_to !== $actor->partner_type)) {
                app(NotificationService::class)->other($actor, 'task_assigned', $task->id.':'.Str::uuid());
            }

            return $task;
        });
    }

    public function status(Device $actor, int $id, string $status): SharedTask
    {
        return DB::transaction(function () use ($actor, $id, $status): SharedTask {
            $this->lockActor($actor);
            $task = SharedTask::query()->where('couple_id', $actor->couple_id)->findOrFail($id);
            if ($task->status !== $status) {
                $task->status = $status;
                $task->completed_at = $status === 'completed' ? now() : null;
                $task->save();
                if ($status === 'completed') {
                    app(NotificationService::class)->other($actor, 'task_completed', $task->id.':'.Str::uuid());
                }
                ActivityLog::record($actor, match ($status) {
                    'completed' => 'task_completed', 'in_progress' => 'task_started', default => 'task_reopened'
                });
            }

            return $task;
        });
    }

    public function trash(Device $actor, int $id, bool $restore = false): SharedTask
    {
        return DB::transaction(function () use ($actor, $id, $restore): SharedTask {
            $this->lockActor($actor);
            $query = SharedTask::query()->where('couple_id', $actor->couple_id);
            if ($restore) {
                $query->onlyTrashed();
            }
            $task = $query->findOrFail($id);
            if ($restore) {
                $task->restore();
            } else {
                $task->delete();
            }
            ActivityLog::record($actor, $restore ? 'task_restored' : 'task_deleted');

            return $task;
        });
    }
}
