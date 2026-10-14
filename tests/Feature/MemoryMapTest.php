<?php

namespace Tests\Feature;

use App\Models\CoupleProfile;
use App\Models\Memory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MemoryMapTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function token(string $partner = 'partner_one'): string
    {
        CoupleProfile::query()->first() ?? CoupleProfile::factory()->create(['id' => 1]);

        return $this->postJson('/api/device/register', ['code' => 'test-recovery-code', 'partner_type' => $partner, 'device_name' => 'Map test phone', 'device_identifier' => (string) Str::uuid()])->assertCreated()->json('data.token');
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['title' => 'Our place', 'memory_date' => '2026-10-09', 'latitude' => 1.3521, 'longitude' => 103.8198], $extra);
    }

    public function test_coordinates_are_shared_preserved_on_unrelated_edits_and_can_be_cleared(): void
    {
        $one = $this->token();
        $two = $this->token('partner_two');
        $memory = $this->withToken($one)->postJson('/api/memories', $this->payload(['latitude' => 0, 'longitude' => 0]))->assertCreated()->assertJsonPath('data.latitude', 0)->json('data');
        $this->withToken($two)->getJson('/api/memories/map')->assertOk()->assertJsonPath('data.items.0.longitude', 0);
        $this->putJson('/api/memories/'.$memory['id'], ['title' => 'New title', 'memory_date' => '2026-10-09'])->assertOk()->assertJsonPath('data.latitude', 0)->assertJsonPath('data.created_by_partner', 'partner_one');
        $this->putJson('/api/memories/'.$memory['id'], $this->payload(['latitude' => -90, 'longitude' => 180]))->assertOk()->assertJsonPath('data.latitude', -90)->assertJsonPath('data.longitude', 180);
        $this->putJson('/api/memories/'.$memory['id'], $this->payload(['latitude' => null, 'longitude' => null]))->assertOk()->assertJsonPath('data.latitude', null)->assertJsonPath('data.longitude', null);
        $this->getJson('/api/memories/map')->assertOk()->assertJsonCount(0, 'data.items')->assertJsonPath('data.without_location', 1);
    }

    public function test_invalid_and_partial_coordinate_updates_cannot_damage_an_existing_pin(): void
    {
        $this->withToken($this->token());
        $id = $this->postJson('/api/memories', $this->payload())->assertCreated()->json('data.id');
        foreach ([['latitude' => 91], ['longitude' => -181], ['latitude' => 'NaN'], ['longitude' => []], ['latitude' => null], ['longitude' => null]] as $bad) {
            $this->putJson('/api/memories/'.$id, $this->payload($bad))->assertUnprocessable();
        }
        $this->putJson('/api/memories/'.$id, ['title' => 'Partial', 'memory_date' => '2026-10-09', 'latitude' => null])->assertUnprocessable();
        $this->getJson('/api/memories/'.$id)->assertOk()->assertJsonPath('data.latitude', 1.3521)->assertJsonPath('data.longitude', 103.8198);
    }

    public function test_map_includes_more_than_one_timeline_page_but_excludes_trash_and_other_couples(): void
    {
        $this->withToken($this->token());
        for ($index = 0; $index < 14; $index++) {
            $this->postJson('/api/memories', $this->payload(['title' => 'Pin '.$index]))->assertCreated();
        }
        $this->postJson('/api/memories', $this->payload(['latitude' => null, 'longitude' => null]))->assertCreated();
        $other = CoupleProfile::factory()->create();
        $foreign = new Memory($this->payload());
        $foreign->couple_id = $other->id;
        $foreign->created_by_partner = 'partner_one';
        $foreign->save();
        $id = Memory::query()->where('couple_id', 1)->first()->id;
        $this->deleteJson('/api/memories/'.$id)->assertOk();
        $result = $this->getJson('/api/memories/map')->assertOk()->assertJsonCount(13, 'data.items')->assertJsonPath('data.without_location', 1)->json('data.items');
        $this->assertNotContains($foreign->id, array_column($result, 'id'));
        $this->assertArrayNotHasKey('couple_id', $result[0]);
        $this->assertArrayNotHasKey('media', $result[0]);
        $this->postJson('/api/memories/'.$id.'/restore')->assertOk();
        $this->getJson('/api/memories/map')->assertOk()->assertJsonCount(14, 'data.items');
    }

    public function test_map_rejects_unauthorized_revoked_and_forged_couple_requests(): void
    {
        $this->getJson('/api/memories/map')->assertUnauthorized();
        $this->withToken($this->token())->getJson('/api/memories/map?couple_id=2')->assertUnprocessable();
        $this->postJson('/api/device/revoke')->assertOk();
        $this->getJson('/api/memories/map')->assertUnauthorized();
    }
}
