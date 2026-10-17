<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationPush;
use App\Models\CalendarEvent;
use App\Models\Challenge;
use App\Models\CoupleProfile;
use App\Models\Device;
use App\Notifications\SpaceNotification;
use App\Services\FirebasePushService;
use App\Services\NotificationService;
use Database\Seeders\DailyQuestionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function token(string $partner = 'partner_one', bool $replace = false): string
    {
        CoupleProfile::query()->first() ?? CoupleProfile::factory()->create(['id' => 1]);

        return $this->postJson('/api/device/'.($replace ? 'replace' : 'register'), ['code' => 'test-recovery-code', 'partner_type' => $partner, 'device_name' => 'Notification test', 'device_identifier' => (string) Str::uuid()])->assertCreated()->json('data.token');
    }

    public function test_inbox_is_scoped_to_permanent_partner_and_survives_phone_replacement(): void
    {
        $one = $this->token();
        $two = $this->token('partner_two');
        $service = app(NotificationService::class);
        $service->send(1, 'partner_two', 'chat_message', 'message-1');
        $service->send(1, 'partner_two', 'chat_message', 'message-1');
        $id = $this->withToken($two)->getJson('/api/notifications')->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.unread_count', 1)->json('data.items.0.id');
        $this->withToken($one)->getJson('/api/notifications')->assertOk()->assertJsonCount(0, 'data.items');
        $this->patchJson('/api/notifications/'.$id.'/read')->assertNotFound();
        $this->patchJson('/api/notifications/read-all')->assertOk()->assertJsonPath('data.unread_count', 0);
        $replacement = $this->token('partner_two', true);
        $this->withToken($two)->getJson('/api/notifications')->assertUnauthorized();
        $this->withToken($replacement)->getJson('/api/notifications')->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->patchJson('/api/notifications/'.$id.'/read')->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->patchJson('/api/notifications/'.$id.'/read')->assertOk();
        $this->getJson('/api/notifications?unread=1')->assertOk()->assertJsonCount(0, 'data.items');
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_chat_tasks_memories_and_challenges_create_generic_partner_alerts_once(): void
    {
        $one = $this->token();
        $two = $this->token('partner_two');
        $this->withToken($one);
        $chat = ['client_id' => (string) Str::uuid(), 'body' => 'PRIVATE SENTINEL'];
        $this->postJson('/api/chat/messages', $chat)->assertCreated();
        $this->postJson('/api/chat/messages', $chat)->assertCreated();
        $task = ['title' => 'PRIVATE SENTINEL', 'assigned_to' => 'partner_two', 'priority' => 'medium'];
        $id = $this->postJson('/api/tasks', $task)->assertCreated()->json('data.id');
        $this->putJson('/api/tasks/'.$id, $task)->assertOk();
        $this->patchJson('/api/tasks/'.$id.'/status', ['status' => 'completed'])->assertOk();
        $this->patchJson('/api/tasks/'.$id.'/status', ['status' => 'completed'])->assertOk();
        $this->postJson('/api/memories', ['title' => 'PRIVATE SENTINEL', 'memory_date' => '2026-10-10'])->assertCreated();
        $challenge = Challenge::factory()->create();
        $enrollment = $this->postJson('/api/challenges/'.$challenge->id.'/start')->assertOk()->json('data.id');
        $this->postJson('/api/couple-challenges/'.$enrollment.'/complete')->assertOk();
        $this->postJson('/api/couple-challenges/'.$enrollment.'/complete')->assertOk();
        $this->postJson('/api/notes', ['title' => 'PRIVATE SENTINEL', 'content' => 'secret', 'visibility' => 'private', 'pinned' => false])->assertCreated();
        $items = $this->withToken($two)->getJson('/api/notifications')->assertOk()->assertJsonCount(5, 'data.items')->json('data.items');
        $this->assertEqualsCanonicalizing(['chat_message', 'task_assigned', 'task_completed', 'memory_created', 'challenge_completed'], array_column($items, 'kind'));
        $this->assertStringNotContainsString('SENTINEL', json_encode($items));
        $this->withToken($one)->getJson('/api/notifications')->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_preferences_validation_and_pagination_do_not_allow_recipient_spoofing(): void
    {
        $one = $this->token();
        $two = $this->token('partner_two');
        $this->withToken($two)->putJson('/api/notifications/settings', ['enabled_types' => []])->assertOk()->assertJsonPath('data.enabled_types', []);
        app(NotificationService::class)->send(1, 'partner_two', 'chat_message', 'disabled');
        $this->assertDatabaseCount('notifications', 0);
        $this->putJson('/api/notifications/settings', ['enabled_types' => ['invalid']])->assertUnprocessable();
        $this->putJson('/api/notifications/settings', ['partner_type' => 'partner_one'])->assertUnprocessable();
        $this->putJson('/api/notifications/settings', ['mode' => 'push'])->assertUnprocessable();
        $this->withToken($one)->getJson('/api/notifications/settings')->assertOk()->assertJsonCount(10, 'data.enabled_types');
        for ($i = 0; $i < 23; $i++) {
            app(NotificationService::class)->send(1, 'partner_one', 'chat_message', (string) $i);
        }
        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(20, 'data.items')->assertJsonPath('data.pagination.total', 23);
        $this->getJson('/api/notifications?page=2')->assertOk()->assertJsonCount(3, 'data.items');
        $this->patchJson('/api/notifications/read-all')->assertOk()->assertJsonPath('data.unread_count', 0);
    }

    public function test_scheduled_reminders_are_timezone_aware_deduplicated_and_ignore_trash_and_dismissed_events(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 11)->setTime(1, 15));
        $one = $this->token();
        CoupleProfile::query()->firstOrFail()->update(['anniversary_date' => '2020-10-11']);
        $this->seed(DailyQuestionSeeder::class);
        $event = CalendarEvent::query()->forceCreate(['couple_id' => 1, 'title' => 'Private calendar', 'event_type' => 'date', 'start_date' => '2026-10-11', 'end_date' => '2026-10-11', 'reminder_at' => now()->subMinute(), 'created_by_partner' => 'partner_one']);
        $trashed = $event->replicate();
        $trashed->save();
        $trashed->delete();
        $dismissed = $event->replicate();
        $dismissed->reminder_dismissed_at = now();
        $dismissed->save();
        $this->artisan('ourspace:notifications')->assertSuccessful();
        $this->artisan('ourspace:notifications')->assertSuccessful();
        $items = $this->withToken($one)->getJson('/api/notifications')->assertOk()->assertJsonCount(3, 'data.items')->json('data.items');
        $this->assertEqualsCanonicalizing(['daily_question', 'anniversary', 'calendar_reminder'], array_column($items, 'kind'));
        $this->assertDatabaseCount('notifications', 6);
        $future = $event->replicate();
        $future->reminder_at = now()->addHour();
        $future->save();
        $reminders = $this->getJson('/api/notifications/reminders')->assertOk()->json('data');
        $this->assertCount(7, $reminders);
        $this->assertStringNotContainsString('Private calendar', json_encode($reminders));
        $this->assertSame('calendar_reminder', $reminders[0]['kind']);
    }

    public function test_partner_birthday_alert_is_delivered_to_the_other_partner_once_per_year(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(9, 5));
        $one = $this->token();
        $two = $this->token('partner_two');
        CoupleProfile::query()->firstOrFail()->update(['partner_two_birthday' => '2000-10-17']);

        $this->artisan('ourspace:notifications')->assertSuccessful();
        $this->artisan('ourspace:notifications')->assertSuccessful();

        $this->withToken($one)->getJson('/api/notifications')->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.kind', 'partner_birthday');
        $this->withToken($two)->getJson('/api/notifications')->assertOk()->assertJsonCount(0, 'data.items');
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_partner_birthdays_are_validated_returned_and_scheduled_as_local_reminders(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(8, 0));
        $one = $this->token();
        $this->withToken($one)->putJson('/api/couple-profile', ['partner_one_birthday' => '2020-02-29', 'partner_two_birthday' => '2000-10-18'])
            ->assertOk()->assertJsonPath('data.partner_one_birthday', '2020-02-29')->assertJsonPath('data.partner_two_birthday', '2000-10-18');
        $this->putJson('/api/couple-profile', ['partner_one_birthday' => '2030-10-17'])->assertUnprocessable()->assertJsonValidationErrors('partner_one_birthday');
        $this->putJson('/api/couple-profile', ['partner_two_birthday' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('partner_two_birthday');

        $reminders = $this->getJson('/api/notifications/reminders')->assertOk()->json('data');
        $birthday = collect($reminders)->firstWhere('kind', 'partner_birthday');
        $this->assertNotNull($birthday);
        $this->assertSame('birthday-2000-10-18-2026', $birthday['key']);
    }

    public function test_rollback_never_leaves_a_notification_and_cross_couple_inbox_is_hidden(): void
    {
        $one = $this->token();
        $service = app(NotificationService::class);
        DB::beginTransaction();
        $service->send(1, 'partner_one', 'chat_message', 'rollback');
        DB::rollBack();
        $this->assertDatabaseCount('notifications', 0);
        $other = CoupleProfile::factory()->create();
        $service->send($other->id, 'partner_one', 'chat_message', 'other-couple');
        $id = DatabaseNotification::query()->firstOrFail()->id;
        $this->withToken($one)->getJson('/api/notifications')->assertOk()->assertJsonCount(0, 'data.items');
        $this->patchJson('/api/notifications/'.$id.'/read')->assertNotFound();
    }

    public function test_push_token_is_encrypted_never_serialized_and_cleared_when_phone_is_replaced(): void
    {
        $one = $this->token();
        $two = $this->token('partner_two');
        $firebase = $this->mock(FirebasePushService::class);
        $firebase->shouldReceive('ready')->andReturn(true);
        $token = 'demo_FCM_token_12345678901234567890';
        $this->withToken($one)->postJson('/api/notifications/push-token', ['token' => $token])->assertOk();
        $device = Device::query()->where('partner_type', 'partner_one')->firstOrFail();
        $this->assertSame($token, $device->push_token);
        $this->assertNotSame($token, $device->getRawOriginal('push_token'));
        $this->assertArrayNotHasKey('push_token', $device->toArray());
        $this->withToken($two)->postJson('/api/notifications/push-token', ['token' => $token])->assertUnprocessable();
        $replacement = $this->token('partner_one', true);
        $device->refresh();
        $this->assertNull($device->push_token);
        $this->assertNull($device->push_token_hash);
        $this->withToken($one)->postJson('/api/notifications/push-token', ['token' => $token])->assertUnauthorized();
        $this->withToken($replacement)->postJson('/api/notifications/push-token', ['token' => $token])->assertOk();
        $this->putJson('/api/notifications/settings', ['mode' => 'off'])->assertOk()->assertJsonPath('data.mode', 'off');
        $this->assertNull(Device::query()->whereNull('revoked_at')->where('partner_type', 'partner_one')->firstOrFail()->push_token);
    }

    public function test_push_job_targets_only_active_partner_phone_once_and_honors_preferences(): void
    {
        Queue::fake();
        $one = $this->token();
        $two = $this->token('partner_two');
        $firebase = $this->mock(FirebasePushService::class);
        $firebase->shouldReceive('ready')->andReturn(true);
        $firebase->shouldReceive('send')->once()->with('demo_FCM_token_12345678901234567890', \Mockery::type('string'), \Mockery::on(fn ($data) => $data['kind'] === 'chat_message'))->andReturn(true);
        $this->withToken($two)->postJson('/api/notifications/push-token', ['token' => 'demo_FCM_token_12345678901234567890'])->assertOk();
        $service = app(NotificationService::class);
        $service->send(1, 'partner_two', 'chat_message', 'job');
        $id = DatabaseNotification::query()->firstOrFail()->id;
        (new SendNotificationPush($id))->handle($firebase);
        (new SendNotificationPush($id))->handle($firebase);
        $this->assertDatabaseCount('notification_push_deliveries', 1);
        $service->send(1, 'partner_two', 'chat_message', 'disabled-after-enqueue');
        $pending = DatabaseNotification::query()->whereNull('push_processed_at')->firstOrFail()->id;
        $this->putJson('/api/notifications/settings', ['enabled_types' => []])->assertOk();
        (new SendNotificationPush($pending))->handle($firebase);
        $this->assertDatabaseCount('notification_push_deliveries', 1);
    }

    public function test_unconfigured_firebase_does_not_prompt_for_or_accept_push_tokens(): void
    {
        $one = $this->token();
        $this->withToken($one)->getJson('/api/notifications/settings')->assertOk()->assertJsonPath('data.firebase_ready', false);
        $this->postJson('/api/notifications/push-token', ['token' => 'demo_FCM_token_12345678901234567890'])->assertStatus(503);
        $this->putJson('/api/notifications/settings', ['mode' => 'local'])->assertOk()->assertJsonPath('data.mode', 'local');
    }

    public function test_pending_push_is_not_sent_to_a_revoked_or_replacement_phone(): void
    {
        Queue::fake();
        $this->travelTo(now()->startOfSecond());
        $this->token();
        $two = $this->token('partner_two');
        $firebase = $this->mock(FirebasePushService::class);
        $firebase->shouldReceive('ready')->andReturn(true);
        $firebase->shouldNotReceive('send');
        $this->withToken($two)->postJson('/api/notifications/push-token', ['token' => 'old_FCM_token_12345678901234567890'])->assertOk();
        app(NotificationService::class)->send(1, 'partner_two', 'chat_message', 'pending-before-replacement');
        $id = DatabaseNotification::query()->firstOrFail()->id;
        $this->travel(2)->seconds();
        $replacement = $this->token('partner_two', true);
        $this->withToken($replacement)->postJson('/api/notifications/push-token', ['token' => 'new_FCM_token_12345678901234567890'])->assertOk();
        (new SendNotificationPush($id))->handle($firebase);
        $this->assertDatabaseCount('notification_push_deliveries', 0);
        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_firebase_http_v1_uses_signed_authorization_and_clears_unregistered_tokens(): void
    {
        Storage::fake('local');
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        Storage::disk('local')->put('firebase-test.json', json_encode(['client_email' => 'test@example.test', 'private_key' => $private]));
        config(['notifications.firebase_project_id' => 'ourspace-test', 'notifications.firebase_credentials' => Storage::disk('local')->path('firebase-test.json')]);
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-access-token']),
            'fcm.googleapis.com/*' => Http::response(['error' => ['details' => [['errorCode' => 'UNREGISTERED']]]], 404),
        ]);
        $firebase = app(FirebasePushService::class);
        $this->assertTrue($firebase->ready());
        $this->assertFalse($firebase->send('test-push-token', (string) Str::uuid(), (new SpaceNotification('chat_message'))->toDatabase(new \stdClass)));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com') && $request->hasHeader('Authorization', 'Bearer test-access-token') && $request['message']['android']['notification']['visibility'] === 'PRIVATE');
        Http::assertSentCount(2);
    }

    public function test_configuration_validation_uses_a_dry_run_without_sending_to_a_phone(): void
    {
        Storage::fake('local');
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        Storage::disk('local')->put('firebase-test.json', json_encode(['client_email' => 'test@example.test', 'private_key' => $private]));
        config(['notifications.firebase_project_id' => 'ourspace-test', 'notifications.firebase_credentials' => Storage::disk('local')->path('firebase-test.json')]);
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-access-token']),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/ourspace-test/messages/validation']),
        ]);
        app(FirebasePushService::class)->validateConfiguration();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com') && $request['validateOnly'] === true && $request['message']['topic'] === 'ourspace-configuration-check' && ! isset($request['message']['token']));
        Http::assertSentCount(2);
    }

    public function test_configuration_check_never_generates_notifications_and_reports_errors_without_secrets(): void
    {
        $firebase = $this->mock(FirebasePushService::class);
        $firebase->shouldReceive('validateConfiguration')->once()->andReturnNull();
        $this->mock(NotificationService::class)->shouldNotReceive('reminders');
        $this->artisan('ourspace:notifications --check-firebase')->expectsOutput('Firebase authorization and Cloud Messaging API validation passed. No notification was sent.')->assertSuccessful();
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_failed_configuration_check_outputs_only_a_safe_error(): void
    {
        $this->mock(FirebasePushService::class)->shouldReceive('validateConfiguration')->once()->andThrow(new \RuntimeException('SECRET SENTINEL'));
        $this->artisan('ourspace:notifications --check-firebase')->expectsOutput('Firebase check failed. Verify the credential file, API permissions and internet connection.')->assertFailed();
    }
}
