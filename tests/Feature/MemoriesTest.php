<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CoupleProfile;
use App\Models\Device;
use App\Models\Memory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MemoriesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function token(string $partner = 'partner_one'): string
    {
        CoupleProfile::query()->first() ?? CoupleProfile::factory()->create(['id' => 1]);

        return $this->postJson('/api/device/register', ['code' => 'test-recovery-code', 'partner_type' => $partner, 'device_name' => 'Test phone', 'device_identifier' => (string) Str::uuid()])->assertCreated()->json('data.token');
    }

    private function photo(string $name = 'photo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6pFYAAAAASUVORK5CYII='));
    }

    private function video(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('clip.mp4', file_get_contents(base_path('../docs/phase-4-demo-video.mp4')))->mimeType('video/mp4');
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['title' => 'Our weekend', 'description' => 'A lovely little day.', 'memory_date' => '2026-01-15', 'location_name' => 'Home', 'tags' => ['Date Night', ' travel ', 'TRAVEL']], $extra);
    }

    private function create(array $extra = []): array
    {
        return $this->postJson('/api/memories', $this->payload($extra))->assertCreated()->json('data');
    }

    public function test_private_memory_routes_require_an_active_token(): void
    {
        $this->getJson('/api/memories')->assertUnauthorized();
        $this->postJson('/api/memories', $this->payload())->assertUnauthorized();
        $this->getJson('/api/memories/1')->assertUnauthorized();
        $this->getJson('/api/memories/1/media/1')->assertUnauthorized();
        $token = $this->token();
        $this->withToken($token)->postJson('/api/device/revoke')->assertOk();
        $this->getJson('/api/memories')->assertUnauthorized();
    }

    public function test_real_mp4_content_is_accepted_without_a_fake_mime_override(): void
    {
        Storage::fake('local');
        $this->withToken($this->token());
        $file = new UploadedFile(base_path('../docs/phase-4-demo-video.mp4'), 'flowers.mp4', null, null, true);
        $this->postJson('/api/memories', $this->payload(['video' => $file]))->assertCreated()->assertJsonPath('data.media.0.mime_type', 'video/mp4');
    }

    public function test_both_partners_share_crud_and_creator_is_derived_from_device(): void
    {
        $one = $this->token();
        $two = $this->token('partner_two');
        $memory = $this->withToken($one)->postJson('/api/memories', $this->payload())->assertCreated()->assertJsonPath('data.created_by_partner', 'partner_one')->assertJsonPath('data.tags', ['date night', 'travel'])->assertJsonMissingPath('data.couple_id')->json('data');
        $this->withToken($two)->getJson('/api/memories/'.$memory['id'])->assertOk()->assertJsonPath('data.title', 'Our weekend')->assertHeader('Cache-Control', 'no-store, private');
        $this->putJson('/api/memories/'.$memory['id'], $this->payload(['title' => 'Our edited weekend', 'tags' => []]))->assertOk()->assertJsonPath('data.tags', [])->assertJsonPath('data.created_by_partner', 'partner_one');
        $this->getJson('/api/memories')->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.title', 'Our edited weekend');
        $this->assertDatabaseHas('activity_logs', ['partner_type' => 'partner_two', 'type' => 'memory_updated']);
        $this->assertDatabaseHas('memories', ['id' => $memory['id'], 'couple_id' => 1]);
    }

    public function test_media_uploads_remain_private_and_video_supports_byte_ranges(): void
    {
        Storage::fake('local');
        $this->withToken($this->token());
        $memory = $this->create(['images' => [$this->photo(), $this->photo('two.png')], 'video' => $this->video()]);
        $this->assertCount(3, $memory['media']);
        $this->assertSame(['image', 'image', 'video'], array_column($memory['media'], 'media_type'));
        $this->assertArrayNotHasKey('file_path', $memory['media'][0]);
        $record = Memory::findOrFail($memory['id']);
        foreach ($record->media as $media) {
            Storage::disk('local')->assertExists($media->file_path);
        }
        $this->get('/api'.$memory['media'][0]['url'])->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->withHeader('Range', 'bytes=0-7')->get('/api'.$memory['media'][2]['url'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-7/'.$memory['media'][2]['file_size']);
        $this->withHeaders(['Range' => 'bytes=99999999-100000000'])->get('/api'.$memory['media'][2]['url'])->assertStatus(416);
    }

    public function test_media_replacement_only_removes_requested_files_and_compacts_order(): void
    {
        Storage::fake('local');
        $this->withToken($this->token());
        $memory = $this->create(['images' => [$this->photo(), $this->photo('two.png')], 'video' => $this->video()]);
        $old = Memory::findOrFail($memory['id'])->media;
        $result = $this->putJson('/api/memories/'.$memory['id'], $this->payload(['tags' => '[]', 'remove_media_ids' => [$old[0]->id, $old[2]->id], 'images' => [$this->photo('new.png')], 'video' => $this->video()]))->assertOk()->assertJsonPath('data.tags', [])->json('data');
        Storage::disk('local')->assertMissing($old[0]->file_path);
        Storage::disk('local')->assertMissing($old[2]->file_path);
        Storage::disk('local')->assertExists($old[1]->file_path);
        $this->assertSame([0, 1, 2], array_column($result['media'], 'sort_order'));
        $this->assertSame($old[1]->id, $result['media'][0]['id']);
    }

    public function test_favorite_is_shared_idempotent_and_filterable(): void
    {
        $one = $this->token();
        $two = $this->token('partner_two');
        $memory = $this->withToken($one)->postJson('/api/memories', $this->payload())->assertCreated()->json('data');
        $this->withToken($two)->patchJson('/api/memories/'.$memory['id'].'/favorite', ['favorite' => true])->assertOk()->assertJsonPath('data.favorite', true);
        $this->patchJson('/api/memories/'.$memory['id'].'/favorite', ['favorite' => true])->assertOk();
        $this->withToken($one)->getJson('/api/memories?favorite=1')->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson('/api/memories?favorite=0')->assertOk()->assertJsonCount(0, 'data.items');
        $this->assertSame(1, ActivityLog::query()->where('type', 'memory_favorite_updated')->count());
        $this->patchJson('/api/memories/'.$memory['id'].'/favorite', [])->assertUnprocessable()->assertJsonValidationErrors('favorite');
    }

    public function test_timeline_search_year_tags_and_pagination_are_scoped_and_stable(): void
    {
        $this->withToken($this->token());
        for ($i = 0; $i < 14; $i++) {
            $this->create(['title' => 'Walk '.$i, 'memory_date' => '2025-01-15', 'tags' => ['café']]);
        }
        $latest = $this->create(['title' => 'Quiet morning', 'description' => 'Coffee & flowers', 'memory_date' => '2026-06-01', 'tags' => ['home']]);
        $first = $this->getJson('/api/memories')->assertOk()->assertJsonCount(12, 'data.items')->assertJsonPath('data.pagination.total', 15)->assertJsonPath('data.items.0.id', $latest['id'])->json('data.items');
        $second = $this->getJson('/api/memories?page=2')->assertOk()->assertJsonCount(3, 'data.items')->json('data.items');
        $this->assertSame([], array_intersect(array_column($first, 'id'), array_column($second, 'id')));
        $this->getJson('/api/memories?year=2025&tag='.urlencode('café').'&q=walk')->assertOk()->assertJsonPath('data.pagination.total', 14);
        $this->getJson('/api/memories?q=flowers')->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson('/api/memories?q=Home')->assertOk()->assertJsonPath('data.pagination.total', 15);
        $this->getJson('/api/memories?tag=home')->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_search_treats_wildcards_literally_and_accepts_zero(): void
    {
        $this->withToken($this->token());
        $this->create(['title' => '100% us', 'description' => 'Only 0', 'location_name' => null, 'tags' => ['0']]);
        $this->create(['title' => 'Another moment', 'description' => null, 'location_name' => null, 'tags' => []]);
        $this->getJson('/api/memories?q=%25')->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson('/api/memories?q=_')->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson('/api/memories?q=0&tag=0')->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson('/api/memories?q='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_deletion_is_recoverable_preserves_media_and_hides_it_until_restore(): void
    {
        Storage::fake('local');
        $this->withToken($this->token());
        $memory = $this->create(['images' => [$this->photo()]]);
        $path = Memory::findOrFail($memory['id'])->media->first()->file_path;
        $this->deleteJson('/api/memories/'.$memory['id'])->assertOk();
        $this->getJson('/api/memories')->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson('/api/memories/'.$memory['id'])->assertNotFound();
        $this->getJson('/api'.$memory['media'][0]['url'])->assertNotFound();
        $this->getJson('/api/memories?trash=1')->assertOk()->assertJsonCount(1, 'data.items');
        Storage::disk('local')->assertExists($path);
        $this->postJson('/api/memories/'.$memory['id'].'/restore')->assertOk()->assertJsonCount(1, 'data.media');
        $this->get('/api'.$memory['media'][0]['url'])->assertOk();
        $this->assertDatabaseHas('activity_logs', ['type' => 'memory_restored']);
    }

    public function test_other_couples_cannot_read_change_media_or_restore_a_memory(): void
    {
        Storage::fake('local');
        $one = $this->token();
        $memory = $this->withToken($one)->postJson('/api/memories', $this->payload(['images' => [$this->photo()]]))->assertCreated()->json('data');
        $other = CoupleProfile::factory()->create();
        $token = 'os_'.str_repeat('a', 64);
        Device::factory()->create(['couple_id' => $other->id, 'token_hash' => hash('sha256', $token)]);
        $this->withToken($token)->getJson('/api/memories')->assertOk()->assertJsonCount(0, 'data.items')->assertJsonPath('data.filters.tags', []);
        $this->getJson('/api/memories/'.$memory['id'])->assertNotFound();
        $this->getJson('/api'.$memory['media'][0]['url'])->assertNotFound();
        $this->putJson('/api/memories/'.$memory['id'], $this->payload())->assertNotFound();
        $this->patchJson('/api/memories/'.$memory['id'].'/favorite', ['favorite' => true])->assertNotFound();
        $this->deleteJson('/api/memories/'.$memory['id'])->assertNotFound();
        $this->withToken($one)->deleteJson('/api/memories/'.$memory['id'])->assertOk();
        $this->withToken($token)->postJson('/api/memories/'.$memory['id'].'/restore')->assertNotFound();
    }

    public function test_forged_identity_coordinates_and_invalid_fields_do_not_create_memories(): void
    {
        $this->withToken($this->token());
        $this->postJson('/api/memories', [])->assertUnprocessable()->assertJsonValidationErrors(['title', 'memory_date']);
        $this->postJson('/api/memories', $this->payload(['couple_id' => 2, 'created_by_partner' => 'partner_two', 'latitude' => 999, 'longitude' => 999]))->assertUnprocessable()->assertJsonValidationErrors(['couple_id', 'created_by_partner', 'latitude', 'longitude']);
        $this->postJson('/api/memories', $this->payload(['title' => str_repeat('x', 151), 'memory_date' => '2026-02-30', 'tags' => [['nested']]]))->assertUnprocessable()->assertJsonValidationErrors(['title', 'memory_date', 'tags.0']);
        $this->postJson('/api/memories', $this->payload(['tags' => array_map(fn ($i) => 'tag'.$i, range(1, 11))]))->assertUnprocessable()->assertJsonValidationErrors('tags');
        $this->getJson('/api/memories?page=0&favorite=invalid&year=10000&couple_id=2')->assertUnprocessable()->assertJsonValidationErrors(['page', 'favorite', 'year', 'couple_id']);
        $this->assertDatabaseCount('memories', 0);
    }

    public function test_media_type_size_limits_and_existing_counts_are_enforced(): void
    {
        Storage::fake('local');
        $this->withToken($this->token());
        $this->postJson('/api/memories', $this->payload(['images' => [UploadedFile::fake()->createWithContent('bad.svg', '<svg><script>alert(1)</script></svg>')]]))->assertUnprocessable()->assertJsonValidationErrors('images.0');
        $this->postJson('/api/memories', $this->payload(['images' => [$this->photo()->size(5121)]]))->assertUnprocessable()->assertJsonValidationErrors('images.0');
        $this->postJson('/api/memories', $this->payload(['video' => $this->video()->size(20481)]))->assertUnprocessable()->assertJsonValidationErrors('video');
        $this->postJson('/api/memories', $this->payload(['video' => new UploadedFile(base_path('../README.md'), 'clip.mp4', null, null, true)]))->assertUnprocessable()->assertJsonValidationErrors('video');
        $memory = $this->create(['images' => array_map(fn ($i) => $this->photo($i.'.png'), range(1, 10)), 'video' => $this->video()]);
        $this->putJson('/api/memories/'.$memory['id'], $this->payload(['images' => [$this->photo()]]))->assertUnprocessable()->assertJsonValidationErrors('images');
        $this->putJson('/api/memories/'.$memory['id'], $this->payload(['video' => $this->video()]))->assertUnprocessable()->assertJsonValidationErrors('video');
        $this->assertCount(11, Memory::findOrFail($memory['id'])->media);
    }

    public function test_media_ids_from_another_memory_are_not_accepted_and_files_remain(): void
    {
        Storage::fake('local');
        $this->withToken($this->token());
        $one = $this->create(['images' => [$this->photo()]]);
        $two = $this->create(['images' => [$this->photo()]]);
        $this->putJson('/api/memories/'.$one['id'], $this->payload(['remove_media_ids' => [$two['media'][0]['id']], 'images' => [$this->photo()]]))->assertUnprocessable()->assertJsonValidationErrors('remove_media_ids');
        $this->assertSame(2, count(Storage::disk('local')->allFiles()));
    }

    public function test_distinct_unicode_tags_and_multipart_tag_clearing_work(): void
    {
        $this->withToken($this->token());
        $memory = $this->create(['tags' => ['cafe', 'café', '旅行']]);
        $this->assertCount(3, $memory['tags']);
        $this->postJson('/api/memories/'.$memory['id'], $this->payload(['_method' => 'PUT', 'tags' => '[]']))->assertOk()->assertJsonPath('data.tags', []);
    }

    public function test_dashboard_shows_latest_live_memory_and_memory_activity(): void
    {
        $this->withToken($this->token());
        $memory = $this->create();
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.latest_memory.id', $memory['id'])->assertJsonPath('data.recent_activity.0.type', 'memory_created');
        $this->deleteJson('/api/memories/'.$memory['id'])->assertOk();
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.latest_memory', null);
    }

    public function test_replaced_phone_loses_memory_access_while_shared_data_survives(): void
    {
        Storage::fake('local');
        $old = $this->token();
        $this->withToken($old);
        $memory = $this->create(['images' => [$this->photo()]]);
        $new = $this->postJson('/api/device/replace', ['code' => 'test-recovery-code', 'partner_type' => 'partner_one', 'device_name' => 'Replacement', 'device_identifier' => (string) Str::uuid()])->assertCreated()->json('data.token');
        $this->withToken($old)->getJson('/api/memories/'.$memory['id'])->assertUnauthorized();
        $this->getJson('/api'.$memory['media'][0]['url'])->assertUnauthorized();
        $this->withToken($new)->getJson('/api/memories/'.$memory['id'])->assertOk();
    }
}
