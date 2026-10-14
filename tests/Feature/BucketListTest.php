<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BucketListItem;
use App\Models\BucketListMedia;
use App\Models\CoupleProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BucketListTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function token(string $partner = 'partner_one'): string
    {
        CoupleProfile::query()->first() ?? CoupleProfile::factory()->create(['id' => 1]);

        return $this->postJson('/api/device/register', ['code' => 'test-recovery-code', 'partner_type' => $partner, 'device_name' => 'Bucket test phone', 'device_identifier' => (string) Str::uuid()])->assertCreated()->json('data.token');
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['title' => 'Visit Japan', 'description' => 'Cherry blossoms together', 'category' => 'travel', 'priority' => 'high', 'target_date' => '2027-04-01', 'notes' => 'Save for our train tickets'], $extra);
    }

    public function test_shared_goals_completion_reopening_and_creator_are_preserved(): void
    {
        $one = $this->token();
        $two = $this->token('partner_two');
        $id = $this->withToken($one)->postJson('/api/bucket-list', $this->payload(['category' => '  Travel  ']))->assertCreated()->assertJsonPath('data.category', 'travel')->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->withToken($two)->getJson('/api/bucket-list')->assertOk()->assertJsonPath('data.items.0.id', $id)->assertJsonPath('data.summary.pending', 1);
        $completed = $this->patchJson('/api/bucket-list/'.$id.'/status', ['status' => 'completed'])->assertOk()->json('data.completed_at');
        $this->assertNotNull($completed);
        $count = ActivityLog::count();
        $this->patchJson('/api/bucket-list/'.$id.'/status', ['status' => 'completed'])->assertOk()->assertJsonPath('data.completed_at', $completed);
        $this->assertSame($count, ActivityLog::count());
        $this->putJson('/api/bucket-list/'.$id, $this->payload(['notes' => 'Tickets booked', 'target_date' => null]))->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.completed_at', $completed)->assertJsonPath('data.created_by_partner', 'partner_one')->assertJsonPath('data.target_date', null);
        $count = ActivityLog::count();
        $this->putJson('/api/bucket-list/'.$id, $this->payload(['notes' => 'Tickets booked', 'target_date' => null]))->assertOk();
        $this->assertSame($count, ActivityLog::count());
        $this->patchJson('/api/bucket-list/'.$id.'/status', ['status' => 'pending'])->assertOk()->assertJsonPath('data.completed_at', null);
        $this->withToken($one)->getJson('/api/bucket-list/'.$id)->assertOk()->assertJsonPath('data.notes', 'Tickets booked');
        $this->assertDatabaseHas('bucket_list_items', ['id' => $id, 'status' => 'pending', 'completed_at' => null]);
    }

    public function test_completion_photos_are_private_shared_and_retained_when_reopened(): void
    {
        Storage::fake('local');
        $one = $this->token();
        $two = $this->token('partner_two');
        $id = $this->withToken($one)->postJson('/api/bucket-list', $this->payload())->assertCreated()->json('data.id');
        $this->patchJson('/api/bucket-list/'.$id.'/status', ['status' => 'completed'])->assertOk();
        $photo = $this->post('/api/bucket-list/'.$id, $this->payload(['_method' => 'PUT', 'images' => [UploadedFile::fake()->image('celebrate.jpg')]]))->assertOk()->assertJsonCount(1, 'data.media')->json('data.media.0');
        $this->assertArrayNotHasKey('file_path', $photo);
        $path = BucketListMedia::findOrFail($photo['id'])->file_path;
        Storage::disk('local')->assertExists($path);
        $this->withToken($two)->get('/api/bucket-list/'.$id.'/media/'.$photo['id'])->assertOk()->assertHeader('content-type', 'image/jpeg')->assertHeader('x-content-type-options', 'nosniff');
        $this->patchJson('/api/bucket-list/'.$id.'/status', ['status' => 'pending'])->assertOk()->assertJsonCount(1, 'data.media');
        $this->get('/api/bucket-list/'.$id.'/media/'.$photo['id'])->assertOk();
        $this->post('/api/bucket-list/'.$id, $this->payload(['_method' => 'PUT', 'images' => [UploadedFile::fake()->image('another.jpg')]]))->assertUnprocessable()->assertJsonValidationErrors('images');
        $this->patchJson('/api/bucket-list/'.$id.'/status', ['status' => 'completed'])->assertOk();
        $this->post('/api/bucket-list/'.$id, $this->payload(['_method' => 'PUT', 'remove_media_ids' => [$photo['id']], 'images' => [UploadedFile::fake()->image('replacement.png')]]))->assertOk()->assertJsonCount(1, 'data.media');
        Storage::disk('local')->assertMissing($path);
        $this->get('/api/bucket-list/'.$id.'/media/'.$photo['id'])->assertNotFound();
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_photo_limits_and_invalid_removals_do_not_partially_update_a_goal(): void
    {
        Storage::fake('local');
        $this->withToken($this->token());
        $id = $this->postJson('/api/bucket-list', $this->payload())->assertCreated()->json('data.id');
        $this->patchJson('/api/bucket-list/'.$id.'/status', ['status' => 'completed'])->assertOk();
        $photos = [];
        for ($i = 0; $i < 10; $i++) {
            $photos[] = UploadedFile::fake()->image('photo'.$i.'.jpg');
        }
        $saved = $this->post('/api/bucket-list/'.$id, $this->payload(['_method' => 'PUT', 'images' => $photos]))->assertOk()->assertJsonCount(10, 'data.media')->json('data');
        $this->post('/api/bucket-list/'.$id, $this->payload(['_method' => 'PUT', 'title' => 'Must not save', 'images' => [UploadedFile::fake()->image('overflow.jpg')]]))->assertUnprocessable()->assertJsonValidationErrors('images');
        $this->putJson('/api/bucket-list/'.$id, $this->payload(['remove_media_ids' => [99999], 'title' => 'Must not save']))->assertUnprocessable()->assertJsonValidationErrors('remove_media_ids');
        $this->getJson('/api/bucket-list/'.$id)->assertOk()->assertJsonPath('data.title', 'Visit Japan')->assertJsonCount(10, 'data.media');
        $this->assertCount(10, Storage::disk('local')->allFiles());
        foreach ([UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml'), UploadedFile::fake()->image('big.jpg')->size(5121), UploadedFile::fake()->image('wide.jpg', 8193, 1)] as $invalid) {
            $this->post('/api/bucket-list/'.$id, $this->payload(['_method' => 'PUT', 'remove_media_ids' => [$saved['media'][0]['id']], 'images' => [$invalid]]))->assertUnprocessable()->assertJsonValidationErrors('images.0');
        }
        $this->assertDatabaseCount('bucket_list_media', 10);
    }

    public function test_trash_hides_photos_and_other_partner_restores_all_content(): void
    {
        Storage::fake('local');
        $one = $this->token();
        $two = $this->token('partner_two');
        $id = $this->withToken($one)->postJson('/api/bucket-list', $this->payload())->assertCreated()->json('data.id');
        $this->patchJson('/api/bucket-list/'.$id.'/status', ['status' => 'completed'])->assertOk();
        $photo = $this->post('/api/bucket-list/'.$id, $this->payload(['_method' => 'PUT', 'images' => [UploadedFile::fake()->image('done.jpg')]]))->assertOk()->json('data.media.0.id');
        $this->deleteJson('/api/bucket-list/'.$id)->assertOk();
        $this->getJson('/api/bucket-list')->assertOk()->assertJsonPath('data.summary.total', 0);
        $this->getJson('/api/bucket-list/'.$id)->assertNotFound();
        $this->get('/api/bucket-list/'.$id.'/media/'.$photo)->assertNotFound();
        $this->withToken($two)->getJson('/api/bucket-list?trash=1')->assertOk()->assertJsonCount(1, 'data.items');
        $this->postJson('/api/bucket-list/'.$id.'/restore')->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.created_by_partner', 'partner_one')->assertJsonCount(1, 'data.media');
        $this->get('/api/bucket-list/'.$id.'/media/'.$photo)->assertOk();
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_failed_photo_persistence_rolls_back_fields_and_cleans_new_files(): void
    {
        Storage::fake('local');
        $this->withToken($this->token());
        $id = $this->postJson('/api/bucket-list', $this->payload())->assertCreated()->json('data.id');
        $this->patchJson('/api/bucket-list/'.$id.'/status', ['status' => 'completed'])->assertOk();
        $event = 'eloquent.creating: '.BucketListMedia::class;
        Event::listen($event, function (): void {
            throw new \RuntimeException('Test photo persistence failure');
        });
        try {
            $this->post('/api/bucket-list/'.$id, $this->payload(['_method' => 'PUT', 'title' => 'Must roll back', 'images' => [UploadedFile::fake()->image('rollback.jpg')]]))->assertStatus(500);
        } finally {
            Event::forget($event);
        }
        $this->getJson('/api/bucket-list/'.$id)->assertOk()->assertJsonPath('data.title', 'Visit Japan')->assertJsonCount(0, 'data.media');
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('bucket_list_media', 0);
    }

    public function test_filters_categories_search_and_pagination_are_scoped_and_literal(): void
    {
        $this->withToken($this->token());
        for ($i = 0; $i < 13; $i++) {
            $this->postJson('/api/bucket-list', $this->payload(['title' => 'Dream '.$i]))->assertCreated();
        }
        $id = $this->postJson('/api/bucket-list', $this->payload(['title' => 'Save 50%_0', 'category' => 'food', 'priority' => 'low', 'target_date' => null]))->assertCreated()->json('data.id');
        $this->getJson('/api/bucket-list?q=%25_0&category=food&priority=low&status=pending')->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $id)->assertJsonPath('data.categories', ['food', 'travel']);
        $this->getJson('/api/bucket-list?q=notfound')->assertOk()->assertJsonCount(0, 'data.items')->assertJsonPath('data.summary.total', 14);
        $this->getJson('/api/bucket-list?q=%27%20OR%201%3D1')->assertOk()->assertJsonCount(0, 'data.items');
        $one = $this->getJson('/api/bucket-list')->assertOk()->assertJsonCount(12, 'data.items')->json('data.items');
        $two = $this->getJson('/api/bucket-list?page=2')->assertOk()->assertJsonCount(2, 'data.items')->json('data.items');
        $this->assertCount(0, array_intersect(array_column($one, 'id'), array_column($two, 'id')));
    }

    public function test_invalid_input_and_forged_identity_or_completion_are_rejected(): void
    {
        $this->withToken($this->token());
        foreach ([['title' => ''], ['category' => ' '], ['priority' => 'urgent'], ['target_date' => '2026-02-30'], ['couple_id' => 999], ['created_by_partner' => 'partner_two'], ['status' => 'completed'], ['completed_at' => now()->toIso8601String()]] as $extra) {
            $this->postJson('/api/bucket-list', $this->payload($extra))->assertUnprocessable()->assertJsonValidationErrors(array_keys($extra));
        }
        $this->post('/api/bucket-list', $this->payload(['images' => [UploadedFile::fake()->image('pending.jpg')]]))->assertUnprocessable()->assertJsonValidationErrors('images');
        $this->assertDatabaseCount('bucket_list_items', 0);
        $this->getJson('/api/bucket-list?status=invalid')->assertUnprocessable();
        $this->getJson('/api/bucket-list?page=0')->assertUnprocessable();
    }

    public function test_foreign_couple_scopes_and_revoked_devices_cannot_access_goals_or_photos(): void
    {
        Storage::fake('local');
        $one = $this->token();
        $two = $this->token('partner_two');
        $id = $this->withToken($one)->postJson('/api/bucket-list', $this->payload())->assertCreated()->json('data.id');
        $foreign = BucketListItem::query()->forceCreate(array_merge($this->payload(), ['couple_id' => CoupleProfile::factory()->create()->id, 'created_by_partner' => 'partner_one', 'status' => 'completed']));
        $photo = $foreign->media()->create(['file_path' => 'foreign.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 1, 'sort_order' => 0]);
        foreach (['get', 'put', 'patch', 'delete', 'restore', 'media'] as $method) {
            (match ($method) {
                'get' => $this->getJson('/api/bucket-list/'.$foreign->id), 'put' => $this->putJson('/api/bucket-list/'.$foreign->id, $this->payload()), 'patch' => $this->patchJson('/api/bucket-list/'.$foreign->id.'/status', ['status' => 'pending']), 'delete' => $this->deleteJson('/api/bucket-list/'.$foreign->id), 'restore' => $this->postJson('/api/bucket-list/'.$foreign->id.'/restore'), 'media' => $this->get('/api/bucket-list/'.$foreign->id.'/media/'.$photo->id)
            })->assertNotFound();
        }
        $this->getJson('/api/bucket-list')->assertOk()->assertJsonCount(1, 'data.items');
        $this->postJson('/api/device/replace', ['code' => 'test-recovery-code', 'partner_type' => 'partner_one', 'device_name' => 'Replacement', 'device_identifier' => (string) Str::uuid()])->assertCreated();
        $this->withToken($one)->getJson('/api/bucket-list')->assertUnauthorized();
        $this->patchJson('/api/bucket-list/'.$id.'/status', ['status' => 'completed'])->assertUnauthorized();
        $this->withToken($two)->getJson('/api/bucket-list/'.$id)->assertOk();
        $this->withHeader('Authorization', '')->getJson('/api/bucket-list')->assertUnauthorized();
    }
}
