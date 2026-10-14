<?php

namespace App\Services;

use App\Http\Resources\CalendarEventResource;
use App\Models\ActivityLog;
use App\Models\CalendarEvent;
use App\Models\CoupleProfile;
use App\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CalendarService
{
    public function today(): string
    {
        return now(config('tasks.timezone'))->toDateString();
    }

    private function lockActor(Device $actor): void
    {
        CoupleProfile::query()->whereKey($actor->couple_id)->lockForUpdate()->firstOrFail();
        abort_unless(Device::query()->whereKey($actor->id)->whereNull('revoked_at')->exists(), 401);
    }

    public function save(Device $actor, array $input, ?int $id = null): CalendarEvent
    {
        return DB::transaction(function () use ($actor, $input, $id): CalendarEvent {
            $this->lockActor($actor);
            $event = $id ? CalendarEvent::query()->where('couple_id', $actor->couple_id)->findOrFail($id) : new CalendarEvent;
            if (! $id) {
                $event->couple_id = $actor->couple_id;
                $event->created_by_partner = $actor->partner_type;
            }
            $event->fill(array_intersect_key($input, array_flip(['title', 'description', 'event_type', 'start_date', 'end_date'])));
            $event->reminder_at = empty($input['reminder_at']) ? null : CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $input['reminder_at'], config('tasks.timezone'))->utc();
            if ($event->isDirty('reminder_at')) {
                $event->reminder_dismissed_at = null;
            }
            $changed = $event->isDirty();
            $event->save();
            if ($changed) {
                ActivityLog::record($actor, $id ? 'calendar_updated' : 'calendar_created');
            }

            return $event;
        });
    }

    public function trash(Device $actor, int $id, bool $restore = false): CalendarEvent
    {
        return DB::transaction(function () use ($actor, $id, $restore): CalendarEvent {
            $this->lockActor($actor);
            $query = CalendarEvent::query()->where('couple_id', $actor->couple_id);
            if ($restore) {
                $query->onlyTrashed();
            }
            $event = $query->findOrFail($id);
            if ($restore) {
                $event->restore();
            } else {
                $event->delete();
            }
            ActivityLog::record($actor, $restore ? 'calendar_restored' : 'calendar_deleted');

            return $event;
        });
    }

    public function dismiss(Device $actor, int $id): CalendarEvent
    {
        return DB::transaction(function () use ($actor, $id): CalendarEvent {
            $this->lockActor($actor);
            $event = CalendarEvent::query()->where('couple_id', $actor->couple_id)->findOrFail($id);
            if ($event->reminder_at && ! $event->reminder_dismissed_at && $event->reminder_at->lte(now())) {
                $event->reminder_dismissed_at = now();
                $event->save();
                ActivityLog::record($actor, 'calendar_reminder_dismissed');
            }

            return $event;
        });
    }

    public function anniversary(CoupleProfile $couple, int $year): ?array
    {
        if (! $couple->anniversary_date || $year < $couple->anniversary_date->year) {
            return null;
        }
        $original = $couple->anniversary_date;
        $day = min($original->day, CarbonImmutable::create($year, $original->month, 1)->daysInMonth);
        $date = sprintf('%04d-%02d-%02d', $year, $original->month, $day);

        return ['id' => 'anniversary-'.$year, 'title' => 'Our anniversary', 'description' => $day !== $original->day ? 'Celebrated on Feb 28 in non-leap years.' : 'Your special day, from your couple profile.', 'event_type' => 'anniversary', 'start_date' => $date, 'end_date' => $date, 'reminder_at' => null, 'reminder_local' => null, 'reminder_dismissed_at' => null, 'created_by_partner' => null, 'virtual' => true];
    }

    public function upcoming(Request $request): array
    {
        $couple = $request->attributes->get('couple');
        $today = $this->today();
        $events = CalendarEvent::query()->where('couple_id', $couple->id)->where('end_date', '>=', $today)->orderBy('start_date')->orderBy('id')->limit(10)->get();
        $items = CalendarEventResource::collection($events)->resolve($request);
        $year = (int) substr($today, 0, 4);
        $anniversary = $this->anniversary($couple, $year);
        if (! $anniversary || $anniversary['start_date'] < $today) {
            $anniversary = $this->anniversary($couple, max($year + 1, $couple->anniversary_date?->year ?? $year + 1));
        }
        if ($anniversary) {
            $items[] = $anniversary;
        }
        usort($items, fn (array $a, array $b): int => strcmp($a['start_date'], $b['start_date']) ?: strcmp((string) $a['id'], (string) $b['id']));
        $due = CalendarEvent::query()->where('couple_id', $couple->id)->where('end_date', '>=', $today)->whereNull('reminder_dismissed_at')->where('reminder_at', '<=', now())->orderBy('reminder_at')->orderBy('id')->limit(10)->get();

        return ['anniversary' => $anniversary, 'items' => array_slice($items, 0, 10), 'due_reminders' => CalendarEventResource::collection($due)->resolve($request), 'today' => $today, 'timezone' => config('tasks.timezone')];
    }
}
