<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicCoupleSetupTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function join(string $code, string $partner): array
    {
        return $this->postJson('/api/device/register', ['code' => $code, 'partner_type' => $partner, 'device_name' => 'Phone', 'device_identifier' => (string) Str::uuid()])->assertCreated()->json('data');
    }

    public function test_independent_spaces_issue_unique_codes_and_join_the_correct_couple(): void
    {
        $one = $this->postJson('/api/spaces', ['partner_one_name' => 'Mahal', 'partner_two_name' => 'Baby'])->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $two = $this->postJson('/api/spaces')->assertCreated()->json('data');

        $first = $this->join($one['setup_code'], 'partner_one');
        $second = $this->join($two['setup_code'], 'partner_one');
        $partner = $this->join($one['setup_code'], 'partner_two');

        $this->assertNotSame($one['setup_code'], $two['setup_code']);
        $this->assertNotSame($first['couple']['id'], $second['couple']['id']);
        $this->assertSame($first['couple']['id'], $partner['couple']['id']);
        $this->assertSame('Mahal', $first['couple']['partner_one_name']);
        $this->assertDatabaseCount('couple_profiles', 2);
        $this->assertDatabaseHas('couple_profiles', ['id' => $first['couple']['id'], 'setup_code_hash' => null, 'recovery_code_hash' => hash('sha256', $one['recovery_code'])]);
        $this->assertArrayNotHasKey('setup_code_hash', $first['couple']);
        $this->withToken($second['token'])->getJson('/api/chat/messages')->assertOk()->assertJsonCount(0, 'data.items');
        $this->withToken($first['token'])->postJson('/api/chat/messages', ['body' => 'Only our space', 'client_id' => (string) Str::uuid()])->assertCreated();
        $this->withToken($second['token'])->getJson('/api/chat/messages')->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_recovery_selects_its_own_space_after_another_space_exists(): void
    {
        $one = $this->postJson('/api/spaces')->assertCreated()->json('data');
        $two = $this->postJson('/api/spaces')->assertCreated()->json('data');
        $first = $this->join($one['setup_code'], 'partner_one');
        $second = $this->join($two['setup_code'], 'partner_one');

        $this->postJson('/api/device/replace', ['code' => $two['recovery_code'], 'partner_type' => 'partner_one', 'device_name' => 'Replacement', 'device_identifier' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.couple.id', $second['couple']['id']);

        $this->withToken($first['token'])->getJson('/api/device/current')->assertOk();
        $this->withToken($second['token'])->getJson('/api/device/current')->assertUnauthorized();
    }

    public function test_space_creation_rejects_injected_identity_and_limits_anonymous_creations(): void
    {
        $this->postJson('/api/spaces', ['couple_id' => 1, 'partner_one_name' => str_repeat('x', 81)])->assertUnprocessable()->assertJsonValidationErrors(['couple_id', 'partner_one_name']);
        $this->assertDatabaseCount('couple_profiles', 0);
        $this->postJson('/api/spaces')->assertCreated();
        $this->postJson('/api/spaces')->assertCreated();
        $this->postJson('/api/spaces')->assertTooManyRequests();
        $this->assertDatabaseCount('couple_profiles', 2);
    }

    public function test_expired_public_invite_returns_422_without_joining(): void
    {
        $this->freezeTime();
        $space = $this->postJson('/api/spaces')->assertCreated()->json('data');
        $this->travel(24)->hours();

        $this->postJson('/api/device/register', ['code' => $space['setup_code'], 'partner_type' => 'partner_two', 'device_name' => 'Phone', 'device_identifier' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseCount('devices', 0);
        $this->assertDatabaseHas('couple_profiles', ['recovery_code_hash' => hash('sha256', $space['recovery_code'])]);
    }
}
