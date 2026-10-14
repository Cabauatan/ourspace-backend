<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationPush;
use App\Models\CoupleProfile;
use App\Models\Device;
use App\Models\PartnerCall;
use App\Services\FirebasePushService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CallingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function pair(): array
    {
        $couple = CoupleProfile::factory()->create();
        $one = 'os_'.Str::random(64);
        $two = 'os_'.Str::random(64);
        $caller = Device::factory()->create(['couple_id' => $couple->id, 'token_hash' => hash('sha256', $one)]);
        $callee = Device::factory()->create(['couple_id' => $couple->id, 'partner_type' => 'partner_two', 'token_hash' => hash('sha256', $two)]);

        return [$one, $two, $caller, $callee];
    }

    private function start(string $token): string
    {
        return $this->withToken($token)->postJson('/api/calls', ['kind' => 'video', 'client_id' => (string) Str::uuid()])->assertCreated()->json('data.id');
    }

    public function test_only_the_two_bound_phones_exchange_encrypted_signals_and_end_cleans_them(): void
    {
        [$one, $two, $caller, $callee] = $this->pair();
        $id = $this->start($one);

        $this->withToken($one)->postJson("/api/calls/$id/signals", ['type' => 'offer', 'sdp' => 'v=0 private-offer'])->assertCreated();
        $this->withToken($one)->getJson("/api/calls/$id/signals")->assertOk()->assertJsonCount(0, 'data.items');
        $this->withToken($two)->getJson("/api/calls/$id/signals")->assertOk()->assertJsonPath('data.items.0.data.sdp', 'v=0 private-offer');
        $this->withToken($two)->postJson("/api/calls/$id/signals", ['type' => 'answer', 'sdp' => 'v=0 answer'])->assertForbidden();
        $this->withToken($one)->postJson("/api/calls/$id/action", ['action' => 'accept'])->assertForbidden();
        $this->withToken($two)->postJson("/api/calls/$id/action", ['action' => 'accept'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson("/api/calls/$id/signals", ['type' => 'answer', 'sdp' => 'v=0 answer'])->assertCreated();
        $this->postJson("/api/calls/$id/signals", ['type' => 'ice', 'candidate' => ['candidate' => 'candidate:1', 'sdpMid' => '0', 'sdpMLineIndex' => 0]])->assertCreated();
        $this->withToken($one)->getJson("/api/calls/$id/signals")->assertOk()->assertJsonCount(2, 'data.items')->assertJsonPath('data.items.0.type', 'answer');
        $this->assertStringNotContainsString('private-offer', DB::table('call_signals')->where('type', 'offer')->value('payload'));
        $this->assertDatabaseHas('partner_calls', ['id' => $id, 'caller_device_id' => $caller->id, 'callee_device_id' => $callee->id, 'status' => 'active']);

        $this->postJson("/api/calls/$id/action", ['action' => 'end'])->assertOk()->assertJsonPath('data.status', 'ended');
        $this->assertDatabaseCount('call_signals', 0);
        $this->getJson('/api/calls/current')->assertOk()->assertJsonPath('data', null);
        $this->getJson('/api/calls')->assertOk()->assertJsonPath('data.items.0.status', 'ended')->assertJsonMissingPath('data.items.0.payload');
    }

    public function test_other_couples_get_404_and_revoked_phones_get_401(): void
    {
        [$one, $two, $caller] = $this->pair();
        [$foreign] = $this->pair();
        $id = $this->start($one);

        $this->withToken($foreign)->getJson("/api/calls/$id/signals")->assertNotFound();
        $this->postJson("/api/calls/$id/action", ['action' => 'end'])->assertNotFound();
        $this->getJson('/api/calls/current')->assertOk()->assertJsonPath('data', null);
        $this->getJson('/api/calls')->assertOk()->assertJsonCount(0, 'data.items');
        $caller->forceFill(['revoked_at' => now()])->save();
        $this->withToken($one)->getJson("/api/calls/$id/signals")->assertUnauthorized();
        $this->withToken($two)->getJson('/api/calls/current')->assertOk()->assertJsonPath('data', null);
        $this->assertDatabaseHas('partner_calls', ['id' => $id, 'status' => 'failed']);
    }

    public function test_retry_is_idempotent_and_a_second_live_call_returns_409(): void
    {
        [$one, $two] = $this->pair();
        $payload = ['kind' => 'voice', 'client_id' => (string) Str::uuid()];
        $id = $this->withToken($one)->postJson('/api/calls', $payload)->assertCreated()->json('data.id');

        $this->postJson('/api/calls', $payload)->assertOk()->assertJsonPath('data.id', $id);
        $this->withToken($two)->postJson('/api/calls', ['kind' => 'voice', 'client_id' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('partner_calls', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->postJson("/api/calls/$id/action", ['action' => 'decline'])->assertOk()->assertJsonPath('data.status', 'declined');
        $this->assertDatabaseCount('call_signals', 0);
    }

    public function test_unanswered_and_unresponsive_calls_expire(): void
    {
        $this->freezeTime();
        [$one, $two] = $this->pair();
        $id = $this->start($one);
        $this->travel(46)->seconds();

        $this->getJson('/api/calls/current')->assertOk()->assertJsonPath('data', null);
        $this->assertDatabaseHas('partner_calls', ['id' => $id, 'status' => 'missed']);
        $active = $this->start($one);
        $this->withToken($two)->postJson("/api/calls/$active/action", ['action' => 'accept'])->assertOk();
        $this->travel(30)->seconds();
        $this->withToken($one)->postJson("/api/calls/$active/action", ['action' => 'heartbeat'])->assertOk();
        $this->travel(16)->seconds();
        $this->getJson('/api/calls/current')->assertOk()->assertJsonPath('data', null);
        $this->assertDatabaseHas('partner_calls', ['id' => $active, 'status' => 'failed']);
    }

    public function test_authentication_validation_signal_roles_and_ephemeral_turn_credentials(): void
    {
        $this->getJson('/api/calls/config')->assertUnauthorized();
        $this->postJson('/api/calls', [])->assertUnauthorized();
        [$one, $two] = $this->pair();
        $this->withToken($one)->postJson('/api/calls', ['kind' => 'video', 'client_id' => (string) Str::uuid(), 'callee_device_id' => 123])->assertUnprocessable()->assertJsonValidationErrors('callee_device_id');
        $this->assertDatabaseCount('partner_calls', 0);
        $id = $this->start($one);
        $this->withToken($two)->postJson("/api/calls/$id/signals", ['type' => 'offer', 'sdp' => 'v=0'])->assertForbidden();
        $this->postJson("/api/calls/$id/signals", ['type' => 'ice', 'candidate' => ['candidate' => str_repeat('x', 2049)]])->assertUnprocessable()->assertJsonValidationErrors('candidate.candidate');
        $this->assertDatabaseCount('call_signals', 0);
        config(['calls.turn_urls' => ['turn:relay.example:3478'], 'calls.turn_secret' => 'server-only-secret', 'calls.relay_only' => true]);
        $config = $this->getJson('/api/calls/config')->assertOk()->assertJsonPath('data.iceTransportPolicy', 'relay')->json('data');
        $this->assertSame(base64_encode(hash_hmac('sha1', $config['iceServers'][1]['username'], 'server-only-secret', true)), $config['iceServers'][1]['credential']);
        $this->assertStringNotContainsString('server-only-secret', json_encode($config));

    }

    public function test_scheduler_expires_abandoned_calls_and_prunes_old_history(): void
    {
        $this->freezeTime();
        [$one] = $this->pair();
        $id = $this->start($one);
        $this->postJson("/api/calls/$id/signals", ['type' => 'offer', 'sdp' => 'v=0'])->assertCreated();
        $old = PartnerCall::factory()->create(['status' => 'ended', 'ended_at' => now()->subDays(31)]);
        $this->travel(46)->seconds();

        $this->artisan('ourspace:calls-prune')->assertSuccessful();

        $this->assertDatabaseHas('partner_calls', ['id' => $id, 'status' => 'missed']);
        $this->assertDatabaseCount('call_signals', 0);
        $this->assertModelMissing($old);
    }

    public function test_ended_call_notification_is_not_pushed_by_a_delayed_job(): void
    {
        [$one, $two, $caller, $callee] = $this->pair();
        $id = $this->start($one);
        $notification = DatabaseNotification::query()->firstOrFail();
        $callee->forceFill(['notification_mode' => 'push', 'push_token' => 'test-token'])->save();
        $this->postJson("/api/calls/$id/action", ['action' => 'end'])->assertOk();
        $firebase = $this->mock(FirebasePushService::class);
        $firebase->shouldReceive('ready')->andReturn(true);
        $firebase->shouldNotReceive('send');

        (new SendNotificationPush($notification->id))->handle($firebase);

        $this->assertNotNull($notification->fresh()->push_processed_at);
        $this->assertDatabaseCount('notification_push_deliveries', 0);
    }
}
