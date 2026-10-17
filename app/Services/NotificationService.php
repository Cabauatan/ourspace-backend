<?php

namespace App\Services;

use App\Jobs\SendNotificationPush;
use App\Models\CalendarEvent;
use App\Models\CoupleProfile;
use App\Models\DailyQuestion;
use App\Models\Device;
use App\Models\NotificationRecipient;
use App\Notifications\SpaceNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Throwable;

class NotificationService
{
    public function recipient(int $coupleId, string $partner): NotificationRecipient
    {
        return NotificationRecipient::query()->firstOrCreate(['couple_id' => $coupleId, 'partner_type' => $partner]);
    }

    public function other(Device $actor, string $kind, string $key): void
    {
        $this->send($actor->couple_id, $actor->partner_type === 'partner_one' ? 'partner_two' : 'partner_one', $kind, $key);
    }

    public function send(int $coupleId, string $partner, string $kind, string $key): void
    {
        DB::transaction(function () use ($coupleId, $partner, $kind, $key): void {
            CoupleProfile::query()->whereKey($coupleId)->lockForUpdate()->firstOrFail();
            $recipient = $this->recipient($coupleId, $partner);
            if (! in_array($kind, $recipient->enabled_types ?? array_keys(SpaceNotification::TYPES), true)) {
                return;
            }
            $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'ourspace:'.$recipient->id.':'.$kind.':'.$key)->toString();
            if ($recipient->notifications()->whereKey($id)->exists()) {
                return;
            }
            $notification = new SpaceNotification($kind, $kind === 'incoming_call' ? $key : null);
            $notification->id = $id;
            $recipient->notify($notification);
            DB::afterCommit(function () use ($id): void {
                if (app(FirebasePushService::class)->ready()) {
                    try {
                        SendNotificationPush::dispatch($id);
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            });
        });
    }

    public function reminders(): void
    {
        $now = CarbonImmutable::now(config('tasks.timezone'));
        CoupleProfile::query()->each(function (CoupleProfile $couple) use ($now): void {
            foreach (['partner_one', 'partner_two'] as $partner) {
                if ($now->hour >= 9 && DailyQuestion::query()->where('active', true)->exists()) {
                    $this->send($couple->id, $partner, 'daily_question', $now->toDateString());
                }
                $anniversary = app(CalendarService::class)->anniversary($couple, $now->year);
                if ($now->hour >= 9 && ($anniversary['start_date'] ?? null) === $now->toDateString()) {
                    $this->send($couple->id, $partner, 'anniversary', $now->toDateString());
                }
                $partnerBirthday = $couple->{$partner === 'partner_one' ? 'partner_two_birthday' : 'partner_one_birthday'};
                if ($now->hour >= 9 && $partnerBirthday && $partnerBirthday->format('m-d') === $now->format('m-d')) {
                    $this->send($couple->id, $partner, 'partner_birthday', $partnerBirthday->format('Y-m-d').':'.$now->year);
                }
                CalendarEvent::query()->where('couple_id', $couple->id)->whereNull('reminder_dismissed_at')->where('end_date', '>=', $now->toDateString())->where('reminder_at', '<=', $now->utc())->each(function (CalendarEvent $event) use ($couple, $partner): void {
                    $this->send($couple->id, $partner, 'calendar_reminder', $event->id.':'.$event->reminder_at->toIso8601String());
                });
            }
        });
    }

    public function localReminders(Device $device): array
    {
        $enabled = $this->recipient($device->couple_id, $device->partner_type)->enabled_types ?? array_keys(SpaceNotification::TYPES);
        $now = CarbonImmutable::now(config('tasks.timezone'));
        $items = [];
        if (in_array('calendar_reminder', $enabled, true)) {
            CalendarEvent::query()->where('couple_id', $device->couple_id)->where('end_date', '>=', $now->toDateString())->whereNull('reminder_dismissed_at')->whereBetween('reminder_at', [$now->utc(), $now->addDays(14)->utc()])->orderBy('reminder_at')->limit(50)->each(function (CalendarEvent $event) use (&$items): void {
                $items[] = ['key' => 'event-'.$event->id, 'kind' => 'calendar_reminder', 'at' => $event->reminder_at->toIso8601String()];
            });
        }
        for ($day = 0; $day < 7; $day++) {
            $at = $now->addDays($day)->setTime(9, 0);
            if ($at->lte($now)) {
                continue;
            }
            if (in_array('daily_question', $enabled, true) && DailyQuestion::query()->where('active', true)->exists()) {
                $items[] = ['key' => 'question-'.$at->toDateString(), 'kind' => 'daily_question', 'at' => $at->utc()->toIso8601String()];
            }
            $couple = CoupleProfile::query()->findOrFail($device->couple_id);
            $anniversary = app(CalendarService::class)->anniversary($couple, $at->year);
            if (in_array('anniversary', $enabled, true) && ($anniversary['start_date'] ?? null) === $at->toDateString()) {
                $items[] = ['key' => 'anniversary-'.$at->toDateString(), 'kind' => 'anniversary', 'at' => $at->utc()->toIso8601String()];
            }
            $partnerBirthday = $couple->{$device->partner_type === 'partner_one' ? 'partner_two_birthday' : 'partner_one_birthday'};
            if (in_array('partner_birthday', $enabled, true) && $partnerBirthday && $partnerBirthday->format('m-d') === $at->format('m-d')) {
                $items[] = ['key' => 'birthday-'.$partnerBirthday->format('Y-m-d').'-'.$at->year, 'kind' => 'partner_birthday', 'at' => $at->utc()->toIso8601String()];
            }
        }

        return $items;
    }
}
