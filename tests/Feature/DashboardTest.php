<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CoupleProfile;
use App\Models\Device;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function join(string $partner = 'partner_one'): string
    {
        CoupleProfile::query()->first() ?? CoupleProfile::factory()->create(['id' => 1]);

        return $this->postJson('/api/device/register', ['code' => 'test-recovery-code', 'partner_type' => $partner, 'device_name' => 'Test phone', 'device_identifier' => (string) Str::uuid()])->assertCreated()->json('data.token');
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('avatar.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6pFYAAAAASUVORK5CYII='));
    }

    public function test_dashboard_and_photo_routes_require_active_device_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
        $this->getJson('/api/couple-profile/avatar/partner_one')->assertUnauthorized();
        $this->postJson('/api/couple-profile/avatar/partner_one')->assertUnauthorized();
        $this->deleteJson('/api/couple-profile/avatar/partner_one')->assertUnauthorized();
        $token = $this->join();
        $this->withToken($token)->postJson('/api/device/revoke')->assertOk();
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_dashboard_is_shared_and_has_no_credentials_or_fake_future_feature_data(): void
    {
        $one = $this->join();
        $two = $this->join('partner_two');
        $first = $this->withToken($one)->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.couple.id', 1)->assertJsonCount(2, 'data.recent_activity')->assertJsonMissingPath('data.couple.setup_code_hash')->assertJsonMissingPath('data.couple.recovery_code_hash')->assertJsonMissingPath('data.token')->assertJsonMissingPath('data.love_points')->json('data');
        $this->withToken($two)->getJson('/api/dashboard')->assertOk()->assertExactJson(['success' => true, 'message' => 'Your shared dashboard.', 'data' => $first])->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_activity_feed_is_scoped_limited_and_stably_sorted(): void
    {
        $token = $this->join();
        $actor = Device::query()->firstOrFail();
        for ($i = 0; $i < 12; $i++) {
            ActivityLog::record($actor, 'profile_updated');
        }
        $other = CoupleProfile::factory()->create();
        ActivityLog::query()->create(['couple_id' => $other->id, 'partner_type' => 'partner_two', 'type' => 'other_couple', 'created_at' => now()->addDay()]);
        $expected = ActivityLog::query()->where('couple_id', 1)->orderByDesc('created_at')->orderByDesc('id')->limit(10)->pluck('id')->all();
        $result = $this->withToken($token)->getJson('/api/dashboard')->assertOk()->assertJsonCount(10, 'data.recent_activity')->assertJsonMissing(['type' => 'other_couple'])->json('data.recent_activity');
        $this->assertSame($expected, array_column($result, 'id'));
    }

    public function test_profile_activity_records_changes_but_not_no_op_saves(): void
    {
        $token = $this->join();
        $this->withToken($token)->putJson('/api/couple-profile', ['partner_one_name' => 'Alex', 'relationship_start_date' => '2020-02-29'])->assertOk();
        $this->putJson('/api/couple-profile', ['partner_one_name' => 'Alex', 'relationship_start_date' => '2020-02-29'])->assertOk();
        $this->assertSame(1, ActivityLog::query()->where('type', 'profile_updated')->count());
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.couple.partner_one_name', 'Alex')->assertJsonPath('data.recent_activity.0.type', 'profile_updated');
    }

    public function test_avatar_is_private_shared_replaceable_and_removable(): void
    {
        Storage::fake('local');
        $one = $this->join();
        $two = $this->join('partner_two');
        $this->withToken($one)->postJson('/api/couple-profile/avatar/partner_one', ['avatar' => $this->image()])->assertOk()->assertJsonPath('data.partner_one_avatar', '/couple-profile/avatar/partner_one');
        $first = CoupleProfile::findOrFail(1)->partner_one_avatar;
        Storage::disk('local')->assertExists($first);
        $this->withToken($two)->get('/api/couple-profile/avatar/partner_one')->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/couple-profile/avatar/partner_one', ['avatar' => $this->image()])->assertOk();
        $second = CoupleProfile::findOrFail(1)->partner_one_avatar;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
        $this->deleteJson('/api/couple-profile/avatar/partner_one')->assertOk()->assertJsonPath('data.partner_one_avatar', null);
        Storage::disk('local')->assertMissing($second);
        $this->getJson('/api/couple-profile/avatar/partner_one')->assertNotFound();
        $this->assertDatabaseHas('activity_logs', ['partner_type' => 'partner_two', 'type' => 'avatar_removed']);
    }

    public function test_avatars_reject_active_content_oversize_uploads_and_forged_scope(): void
    {
        Storage::fake('local');
        $this->withToken($this->join());
        $this->postJson('/api/couple-profile/avatar/partner_one', [])->assertUnprocessable()->assertJsonValidationErrors('avatar');
        $this->postJson('/api/couple-profile/avatar/partner_one', ['avatar' => UploadedFile::fake()->createWithContent('bad.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])->assertUnprocessable()->assertJsonValidationErrors('avatar');
        $this->postJson('/api/couple-profile/avatar/partner_one', ['avatar' => $this->image()->size(2050)])->assertUnprocessable()->assertJsonValidationErrors('avatar');
        $this->postJson('/api/couple-profile/avatar/partner_one', ['avatar' => $this->image(), 'couple_id' => 2])->assertUnprocessable()->assertJsonValidationErrors('couple_id');
        $this->postJson('/api/couple-profile/avatar/admin', ['avatar' => $this->image()])->assertNotFound();
        $this->assertNull(CoupleProfile::findOrFail(1)->partner_one_avatar);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_another_couples_avatar_cannot_be_requested_using_client_scope(): void
    {
        Storage::fake('local');
        $token = $this->join();
        $other = CoupleProfile::factory()->create();
        Storage::disk('local')->put('avatars/other.png', 'private');
        $other->forceFill(['partner_one_avatar' => 'avatars/other.png'])->save();
        $this->withToken($token)->getJson('/api/couple-profile/avatar/partner_one?couple_id='.$other->id)->assertNotFound();
        $this->getJson('/api/dashboard?couple_id='.$other->id)->assertOk()->assertJsonPath('data.couple.id', 1)->assertJsonPath('data.couple.partner_one_avatar', null);
    }

    public function test_replacement_and_revocation_events_preserve_shared_profile_and_avatars(): void
    {
        Storage::fake('local');
        $token = $this->join();
        $this->withToken($token)->postJson('/api/couple-profile/avatar/partner_two', ['avatar' => $this->image()])->assertOk();
        $path = CoupleProfile::findOrFail(1)->partner_two_avatar;
        $replacement = $this->postJson('/api/device/replace', ['code' => 'test-recovery-code', 'partner_type' => 'partner_one', 'device_name' => 'New phone', 'device_identifier' => (string) Str::uuid()])->assertCreated()->json('data.token');
        $this->withToken($token)->getJson('/api/couple-profile/avatar/partner_two')->assertUnauthorized();
        $this->withToken($replacement)->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.recent_activity.0.type', 'phone_replaced')->assertJsonPath('data.couple.partner_two_avatar', '/couple-profile/avatar/partner_two');
        $this->postJson('/api/device/revoke')->assertOk();
        $this->assertSame($path, CoupleProfile::findOrFail(1)->partner_two_avatar);
        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseHas('activity_logs',['type' => 'phone_revoked']);
    }
}
