<?php

namespace App\Console\Commands;

use App\Jobs\SendNotificationPush;
use App\Services\FirebasePushService;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

class SendNotificationReminders extends Command
{
    protected $signature = 'ourspace:notifications {--check-firebase : Validate Firebase authorization without sending a notification}';

    protected $description = 'Create due shared reminders and dispatch pending push notifications';

    public function handle(NotificationService $service, FirebasePushService $firebase): int
    {
        if ($this->option('check-firebase')) {
            try {
                $firebase->validateConfiguration();
            } catch (\Throwable $exception) {
                $this->error('Firebase check failed. Verify the credential file, API permissions and internet connection.');

                return self::FAILURE;
            }
            $this->info('Firebase authorization and Cloud Messaging API validation passed. No notification was sent.');

            return self::SUCCESS;
        }
        $service->reminders();
        if ($firebase->ready()) {
            DatabaseNotification::query()->whereNull('push_processed_at')->whereNull('read_at')->where('created_at', '>=', now()->subDay())->limit(100)->get()->each(fn ($notification) => SendNotificationPush::dispatch($notification->id));
        }
        $this->info('Due notifications processed.');

        return self::SUCCESS;
    }
}
