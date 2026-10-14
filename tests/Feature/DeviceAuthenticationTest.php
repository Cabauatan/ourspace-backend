<?php

namespace Tests\Feature;

use App\Models\CoupleProfile;
use App\Models\Device;
use App\Services\DeviceService;
use Database\Seeders\PhaseTwoPreviewSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceAuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function couple(): CoupleProfile
    {
        return CoupleProfile::factory()->create(['id' => 1]);
    }

    public function test_preview_fixture_cannot_seed_the_application_or_test_database(): void
    {
        $this->expectException(\LogicException::class);
        app(PhaseTwoPreviewSeeder::class)->run();
    }

    public function test_device_registration_time_stays_fixed_after_other_device_updates(): void
    {
        $this->couple();
        $one = $this->join();
        $registered = Device::findOrFail($one['device']['id'])->registered_at->toIso8601String();
        $this->travel(6)->minutes();
        $this->withToken($one['token'])->getJson('/api/device/current')->assertOk();
        $this->assertSame($registered, Device::findOrFail($one['device']['id'])->registered_at->toIso8601String());
        $this->postJson('/api/device/revoke')->assertOk();
        $this->assertSame($registered, Device::findOrFail($one['device']['id'])->registered_at->toIso8601String());
    }

    private function payload(string $partner = 'partner_one', string $code = 'test-setup-code'): array
    {
        return ['code' => $code, 'partner_type' => $partner, 'device_name' => 'Test phone', 'device_identifier' => (string) Str::uuid()];
    }

    private function join(string $partner = 'partner_one'): array
    {
        return $this->postJson('/api/device/register', $this->payload($partner))->assertCreated()->json('data');
    }

    public function test_private_initialization_stores_only_code_hashes_and_cannot_create_a_second_couple(): void
    {
        $result = app(DeviceService::class)->initialize('One', 'Two');

        $this->assertSame(hash('sha256', $result['setup_code']), $result['couple']->setup_code_hash);
        $this->assertSame(hash('sha256', $result['recovery_code']), $result['couple']->recovery_code_hash);
        $this->assertArrayNotHasKey('recovery_code_hash', $result['couple']->toArray());
        $this->artisan('ourspace:setup')->assertFailed();
        $this->assertDatabaseCount('couple_profiles', 1);
    }

    public function test_both_partners_register_to_the_same_couple_and_only_token_hashes_are_stored(): void
    {
        $couple = $this->couple();
        $one = $this->join();
        $two = $this->join('partner_two');

        $this->assertNotSame($one['token'], $two['token']);
        $this->assertDatabaseHas('devices', ['id' => $one['device']['id'], 'token_hash' => hash('sha256', $one['token']), 'couple_id' => $couple->id]);
        $this->assertSame($one['couple']['id'], $two['couple']['id']);
        $this->withToken($one['token'])->getJson('/api/device/current')->assertOk()->assertJsonPath('data.device.partner_type', 'partner_one')->assertJsonMissingPath('data.token')->assertJsonMissingPath('data.device.token_hash');
        $this->withToken($two['token'])->getJson('/api/device/current')->assertOk()->assertJsonPath('data.device.partner_type', 'partner_two');
        $this->assertNull($couple->fresh()->setup_code_hash);
    }

    public function test_invalid_code_does_not_register_a_device(): void
    {
        $this->couple();

        $this->postJson('/api/device/register', $this->payload(code: 'wrong'))->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_registration_saves_custom_nicknames_and_blank_second_join_preserves_them(): void
    {
        $couple = $this->couple();

        $this->postJson('/api/device/register', [...$this->payload(), 'partner_one_name' => ' Mahal 💛 ', 'partner_two_name' => ' Baby '])
            ->assertCreated()->assertJsonPath('data.couple.partner_one_name', 'Mahal 💛')->assertJsonPath('data.couple.partner_two_name', 'Baby');
        $this->postJson('/api/device/register', [...$this->payload('partner_two'), 'partner_one_name' => ' ', 'partner_two_name' => null])
            ->assertCreated()->assertJsonPath('data.couple.partner_one_name', 'Mahal 💛')->assertJsonPath('data.couple.partner_two_name', 'Baby');

        $this->assertDatabaseHas('couple_profiles', ['id' => $couple->id, 'partner_one_name' => 'Mahal 💛', 'partner_two_name' => 'Baby']);
        $this->assertDatabaseCount('devices', 2);
    }

    public function test_invalid_nicknames_return_422_without_registering_or_changing_profile(): void
    {
        $couple = $this->couple();

        $this->postJson('/api/device/register', [...$this->payload(), 'partner_one_name' => str_repeat('x', 81), 'partner_two_name' => ['Baby']])
            ->assertUnprocessable()->assertJsonValidationErrors(['partner_one_name', 'partner_two_name']);

        $this->assertDatabaseHas('couple_profiles', ['id' => $couple->id, 'partner_one_name' => $couple->partner_one_name, 'partner_two_name' => $couple->partner_two_name]);
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_invalid_code_returns_422_without_changing_nicknames(): void
    {
        $couple = $this->couple();

        $this->postJson('/api/device/register', [...$this->payload(code: 'wrong'), 'partner_one_name' => 'Mahal', 'partner_two_name' => 'Baby'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->assertDatabaseHas('couple_profiles', ['id' => $couple->id, 'partner_one_name' => $couple->partner_one_name, 'partner_two_name' => $couple->partner_two_name]);
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_replacement_preserves_saved_nicknames_even_if_names_are_submitted(): void
    {
        $couple = $this->couple();
        $this->join();

        $this->postJson('/api/device/replace', [...$this->payload(code: 'test-recovery-code'), 'partner_one_name' => 'Changed'])
            ->assertCreated()->assertJsonPath('data.couple.partner_one_name', $couple->partner_one_name);

        $this->assertDatabaseHas('couple_profiles', ['id' => $couple->id, 'partner_one_name' => $couple->partner_one_name, 'partner_two_name' => $couple->partner_two_name]);
    }

    public function test_expired_setup_code_is_rejected_but_recovery_still_works(): void
    {
        $couple = $this->couple();
        $couple->forceFill(['setup_code_expires_at' => now()->subMinute()])->save();

        $this->postJson('/api/device/register', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/device/register', $this->payload(code: 'test-recovery-code'))->assertCreated();
    }

    public function test_missing_and_invalid_tokens_cannot_read_private_data(): void
    {
        $this->couple();

        $this->getJson('/api/couple-profile')->assertUnauthorized();
        $this->withToken('os_'.str_repeat('x', 64))->getJson('/api/devices')->assertUnauthorized();
        $this->withToken('not-a-token')->getJson('/api/device/current')->assertUnauthorized();
    }

    public function test_partner_selection_is_validated_and_couple_cannot_be_supplied(): void
    {
        $this->couple();

        $this->postJson('/api/device/register', [...$this->payload(), 'partner_type' => 'both', 'couple_id' => 2])->assertUnprocessable()->assertJsonValidationErrors(['partner_type', 'couple_id']);
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_regular_registration_does_not_replace_an_active_phone(): void
    {
        $this->couple();
        $original = $this->join();

        $this->postJson('/api/device/register', $this->payload(code: 'test-recovery-code'))->assertUnprocessable()->assertJsonValidationErrors('partner_type');
        $this->withToken($original['token'])->getJson('/api/device/current')->assertOk();
        $this->assertDatabaseCount('devices', 1);
    }

    public function test_replacement_preserves_couple_data_and_partner_two_access(): void
    {
        $couple = $this->couple();
        $one = $this->join();
        $two = $this->join('partner_two');
        $this->withToken($two['token'])->putJson('/api/couple-profile', ['partner_one_name' => 'Marcel', 'relationship_start_date' => '2020-01-01'])->assertOk();

        $replacement = $this->postJson('/api/device/replace', $this->payload(code: 'test-recovery-code'))->assertCreated()->json('data');

        $this->assertNotSame($one['token'], $replacement['token']);
        $this->withToken($one['token'])->getJson('/api/couple-profile')->assertUnauthorized();
        $this->withToken($replacement['token'])->getJson('/api/device/current')->assertOk()->assertJsonPath('data.device.partner_type', 'partner_one')->assertJsonPath('data.couple.partner_one_name', 'Marcel');
        $this->withToken($two['token'])->getJson('/api/couple-profile')->assertOk()->assertJsonPath('data.id', $couple->id)->assertJsonPath('data.relationship_start_date', '2020-01-01');
        $this->assertDatabaseHas('devices', ['id' => $one['device']['id'], 'is_primary' => false]);
        $this->assertDatabaseCount('couple_profiles', 1);
    }

    public function test_setup_code_cannot_be_used_for_phone_replacement(): void
    {
        $this->couple();
        $one = $this->join();

        $this->postJson('/api/device/replace', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->withToken($one['token'])->getJson('/api/device/current')->assertOk();
        $this->assertDatabaseCount('devices', 1);
    }

    public function test_revocation_immediately_blocks_the_token(): void
    {
        $this->couple();
        $one = $this->join();

        $this->withToken($one['token'])->postJson('/api/device/revoke')->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->withToken($one['token'])->getJson('/api/couple-profile')->assertUnauthorized();
        $this->withToken($one['token'])->postJson('/api/device/revoke')->assertUnauthorized();
    }

    public function test_partner_can_revoke_a_lost_phone_without_losing_their_own_access(): void
    {
        $this->couple();
        $one = $this->join();
        $two = $this->join('partner_two');

        $this->withToken($two['token'])->postJson('/api/device/revoke', ['device_id' => $one['device']['id']])->assertOk();
        $this->withToken($one['token'])->getJson('/api/devices')->assertUnauthorized();
        $this->withToken($two['token'])->getJson('/api/devices')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_revoking_a_device_outside_the_authenticated_couple_is_not_allowed(): void
    {
        $this->couple();
        $one = $this->join();
        $outside = Device::factory()->create(['couple_id' => CoupleProfile::factory()->create()->id, 'token_hash' => hash('sha256', 'outside')]);

        $this->withToken($one['token'])->postJson('/api/device/revoke', ['device_id' => $outside->id])->assertNotFound();
        $this->assertNull($outside->fresh()->revoked_at);
        $this->withToken($one['token'])->getJson('/api/devices')->assertJsonCount(1, 'data');
    }

    public function test_profile_updates_are_shared_and_private_hashes_are_never_returned(): void
    {
        $this->couple();
        $one = $this->join();
        $two = $this->join('partner_two');

        $this->withToken($one['token'])->putJson('/api/couple-profile', ['partner_two_name' => 'My partner', 'anniversary_date' => '2020-06-01'])->assertOk();
        $this->withToken($two['token'])->getJson('/api/couple-profile')->assertOk()->assertJsonPath('data.partner_two_name', 'My partner')->assertJsonMissingPath('data.recovery_code_hash')->assertJsonMissingPath('data.setup_code_hash');
    }

    public function test_profile_identity_and_secret_hashes_cannot_be_forged(): void
    {
        $this->couple();
        $one = $this->join();

        $this->withToken($one['token'])->putJson('/api/couple-profile', ['couple_id' => 2, 'partner_type' => 'partner_two', 'recovery_code_hash' => 'attacker'])->assertUnprocessable()->assertJsonValidationErrors(['couple_id', 'partner_type', 'recovery_code_hash']);
    }

    public function test_last_seen_is_updated_only_after_the_write_interval(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->couple();
        $one = $this->join();
        $registered = Device::findOrFail($one['device']['id'])->last_seen_at;

        $this->travel(1)->minutes();
        $this->withToken($one['token'])->getJson('/api/device/current')->assertOk();
        $this->assertTrue($registered->equalTo(Device::findOrFail($one['device']['id'])->last_seen_at));
        $this->travel(5)->minutes();
        $this->withToken($one['token'])->getJson('/api/device/current')->assertOk();
        $this->assertTrue(now()->equalTo(Device::findOrFail($one['device']['id'])->last_seen_at));
    }

    public function test_recovery_rotation_invalidates_only_the_old_recovery_code(): void
    {
        $this->couple();
        $one = $this->join();
        $newCode = app(DeviceService::class)->rotateRecoveryCode();

        $this->postJson('/api/device/replace', $this->payload(code: 'test-recovery-code'))->assertUnprocessable();
        $this->withToken($one['token'])->getJson('/api/device/current')->assertOk();
        $this->postJson('/api/device/replace', $this->payload(code: $newCode))->assertCreated();
    }

    public function test_setup_cannot_restore_an_already_registered_and_revoked_partner(): void
    {
        $this->couple();
        $one = $this->join();
        $this->withToken($one['token'])->postJson('/api/device/revoke')->assertOk();

        $this->postJson('/api/device/register', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/device/register', $this->payload(code: 'test-recovery-code'))->assertCreated();
    }

    public function test_registration_and_recovery_share_the_brute_force_limit(): void
    {
        $this->couple();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/device/register', $this->payload(code: 'wrong'))->assertUnprocessable();
        }

        $this->postJson('/api/device/replace', $this->payload(code: 'wrong'))->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertDatabaseCount('devices', 0);
    }
}
