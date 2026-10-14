<?php

namespace App\Jobs;

use App\Models\CoupleProfile;
use App\Models\Device;
use App\Models\NotificationRecipient;
use App\Models\PartnerCall;
use App\Notifications\SpaceNotification;
use App\Services\FirebasePushService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

class SendNotificationPush implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public int $timeout = 60;

    public array $backoff = [30, 120];

    public function __construct(public string $notificationId) {}

    public function uniqueId(): string
    {
        return $this->notificationId;
    }

    public function handle(FirebasePushService $firebase): void
    {
        if (! $firebase->ready()) {
            return;
        }
        $notification = DatabaseNotification::query()->find($this->notificationId);
        if (! $notification || $notification->push_processed_at || $notification->created_at->lt(now()->subDay()) || $notification->read_at) {
            return;
        }
        $recipient = NotificationRecipient::query()->find($notification->notifiable_id);
        if (! $recipient) {
            return;
        }
        DB::transaction(function () use ($notification, $recipient, $firebase): void {
            // Serialize delivery with phone replacement so revoked credentials cannot receive new sends.
            CoupleProfile::query()->whereKey($recipient->couple_id)->lockForUpdate()->firstOrFail();
            $notification->refresh();
            if ($notification->push_processed_at || $notification->read_at) {
                return;
            }
            $recipient->refresh();
            $call = null;
            if ($notification->data['kind'] === 'incoming_call') {
                $call = PartnerCall::query()->where('couple_id', $recipient->couple_id)->find($notification->data['call_id'] ?? '');
                if (! $call || $call->status !== 'ringing' || $call->created_at->lte(now()->subSeconds(45))) {
                    $notification->update(['push_processed_at' => now()]);

                    return;
                }
            }
            if (! in_array($notification->data['kind'], $recipient->enabled_types ?? array_keys(SpaceNotification::TYPES), true)) {
                $notification->update(['push_processed_at' => now()]);

                return;
            }
            $devices = Device::query()->where('couple_id', $recipient->couple_id)->where('partner_type', $recipient->partner_type)->whereNull('revoked_at')->where('notification_mode', 'push')->whereNotNull('push_token')->where('registered_at', '<=', $notification->created_at)->get();
            foreach ($devices as $device) {
                if ($call && $device->id !== $call->callee_device_id) {
                    continue;
                }
                if (DB::table('notification_push_deliveries')->where('notification_id', $notification->id)->where('device_id', $device->id)->exists()) {
                    continue;
                }
                if (! $firebase->send($device->push_token, $notification->id, $notification->data)) {
                    $device->push_token = null;
                    $device->push_token_hash = null;
                    $device->notification_mode = 'off';
                    $device->save();
                }
                DB::table('notification_push_deliveries')->insert(['notification_id' => $notification->id, 'device_id' => $device->id, 'sent_at' => now()]);
            }
            $notification->update(['push_processed_at' => now()]);
        });
    }
}
