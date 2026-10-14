<?php

namespace App\Services;

use App\Http\Resources\MoodResource;
use App\Models\ActivityLog;
use App\Models\CoupleProfile;
use App\Models\Device;
use App\Models\Mood;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MoodService
{
    public function today(): string
    {
        return now(config('tasks.timezone'))->toDateString();
    }

    public function todayData(Request $request): array
    {
        $today = $this->today();
        $items = Mood::query()->where('couple_id', $request->user()->couple_id)->where('mood_date', $today)->orderBy('partner_type')->get();

        return ['today' => $today, 'timezone' => config('tasks.timezone'), 'items' => MoodResource::collection($items)->resolve($request)];
    }

    public function save(Device $actor, array $input): Mood
    {
        return DB::transaction(function () use ($actor, $input): Mood {
            CoupleProfile::query()->whereKey($actor->couple_id)->lockForUpdate()->firstOrFail();
            abort_unless(Device::query()->whereKey($actor->id)->whereNull('revoked_at')->exists(), 401);
            $today = $this->today();
            if ($input['mood_date'] !== $today) {
                throw ValidationException::withMessages(['mood_date' => 'You can only update today’s mood. Refresh for the current shared date.']);
            }
            $entry = Mood::query()->where('couple_id', $actor->couple_id)->where('partner_type', $actor->partner_type)->where('mood_date', $today)->first();
            $created = ! $entry;
            $entry ??= new Mood;
            if ($created) {
                $entry->couple_id = $actor->couple_id;
                $entry->partner_type = $actor->partner_type;
                $entry->mood_date = $today;
            }
            $entry->fill(['mood' => $input['mood'], 'note' => $input['note'] ?? null]);
            if ($entry->isDirty()) {
                $entry->save();
                ActivityLog::record($actor, $created ? 'mood_created' : 'mood_updated');
            }

            return $entry;
        });
    }
}
