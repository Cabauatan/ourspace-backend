<?php

namespace Tests\Feature;

use App\Events\ChallengesChanged;
use App\Models\Challenge;
use App\Models\CoupleChallenge;
use App\Models\CoupleProfile;
use Database\Seeders\ChallengeSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChallengesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function token(string $partner = 'partner_one'): string
    {
        CoupleProfile::query()->first() ?? CoupleProfile::factory()->create(['id' => 1]);

        return $this->postJson('/api/device/register', ['code' => 'test-recovery-code', 'partner_type' => $partner, 'device_name' => 'Challenge test', 'device_identifier' => (string) Str::uuid()])->assertCreated()->json('data.token');
    }

    private function join(Challenge $challenge): array
    {
        return $this->postJson('/api/challenges/'.$challenge->id.'/start')->assertOk()->json('data');
    }

    public function test_catalog_seeding_is_idempotent_and_never_initializes_private_data(): void
    {
        $this->seed(ChallengeSeeder::class);
        $this->seed(ChallengeSeeder::class);
        $this->assertDatabaseCount('challenges', 8);
        $this->assertDatabaseCount('achievements', 5);
        $this->assertDatabaseCount('couple_profiles', 0);
        $this->assertDatabaseCount('devices', 0);
        $this->getJson('/api/challenges')->assertUnauthorized();
        $this->getJson('/api/challenges/progress')->assertUnauthorized();
    }

    public function test_start_is_shared_idempotent_and_preserves_reward_snapshot(): void
    {
        $one = $this->token();
        $two = $this->token('partner_two');
        $challenge = Challenge::factory()->create(['title' => 'Original title', 'points' => 50]);
        $this->withToken($one);
        $joined = $this->join($challenge);
        $this->withToken($two);
        $this->assertSame($joined['id'], $this->join($challenge)['id']);
        $challenge->update(['title' => 'Changed title', 'points' => 999, 'active' => false]);
        $this->postJson('/api/couple-challenges/'.$joined['id'].'/complete')->assertOk()->assertJsonPath('data.points', 50)->assertJsonPath('data.title', 'Original title')->assertJsonPath('data.completed_by_partner', 'partner_two');
        $completed = CoupleChallenge::findOrFail($joined['id'])->completed_at->toIso8601String();
        $this->withToken($one)->postJson('/api/couple-challenges/'.$joined['id'].'/complete')->assertOk()->assertJsonPath('data.completed_by_partner', 'partner_two')->assertJsonPath('data.completed_at', $completed);
        $this->getJson('/api/challenges/progress')->assertOk()->assertJsonPath('data.total_points', 50)->assertJsonPath('data.level.number', 2)->assertJsonPath('data.completed_count', 1);
        $this->assertDatabaseCount('couple_challenges', 1);
        $this->assertDatabaseCount('activity_logs', 4);
    }

    public function test_completion_unlocks_points_levels_and_badges_once_for_both_partners(): void
    {
        $one = $this->token();
        $two = $this->token('partner_two');
        $this->seed(ChallengeSeeder::class);
        $this->withToken($one)->getJson('/api/challenges/progress')->assertOk()->assertJsonPath('data.total_points', 0)->assertJsonPath('data.level.number', 1)->assertJsonPath('data.points_to_next', 50);
        foreach (Challenge::query()->orderBy('id')->get() as $challenge) {
            $joined = $this->join($challenge);
            $this->postJson('/api/couple-challenges/'.$joined['id'].'/complete')->assertOk();
            $this->postJson('/api/couple-challenges/'.$joined['id'].'/complete')->assertOk();
        }
        foreach ([$one, $two] as $token) {
            $progress = $this->withToken($token)->getJson('/api/challenges/progress')->assertOk()->assertJsonPath('data.total_points', 320)->assertJsonPath('data.completed_count', 8)->assertJsonPath('data.category_count', 4)->assertJsonPath('data.level.name', 'Soulmates')->assertJsonPath('data.next_level', null)->assertJsonPath('data.progress_percent', 100)->json('data');
            $this->assertCount(5, array_filter($progress['achievements'], fn (array $badge) => $badge['unlocked_at'] !== null));
            $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.love_progress.total_points', 320);
        }
        $this->assertDatabaseCount('couple_achievements', 5);
    }

    public function test_pause_resume_and_final_completion_cannot_farm_points(): void
    {
        $this->withToken($this->token());
        $challenge = Challenge::factory()->create();
        $joined = $this->join($challenge);
        $path = '/api/couple-challenges/'.$joined['id'];
        $this->patchJson($path.'/status', ['status' => 'cancelled'])->assertOk();
        $this->postJson($path.'/complete')->assertConflict();
        $this->assertSame($joined['id'], $this->join($challenge)['id']);
        $this->patchJson($path.'/status', ['status' => 'in_progress'])->assertOk();
        $this->postJson($path.'/complete')->assertOk();
        $this->patchJson($path.'/status', ['status' => 'in_progress'])->assertConflict();
        $this->patchJson($path.'/status', ['status' => 'cancelled'])->assertConflict();
        $this->getJson('/api/challenges/progress')->assertOk()->assertJsonPath('data.total_points', 20);
        $inactive = Challenge::factory()->create(['active' => false]);
        $this->postJson('/api/challenges/'.$inactive->id.'/start')->assertNotFound();
    }

    public function test_foreign_enrollments_and_forged_rewards_are_rejected_and_revoked_devices_lose_access(): void
    {
        $token = $this->token();
        $foreign = CoupleChallenge::factory()->create();
        $this->withToken($token);
        $this->patchJson('/api/couple-challenges/'.$foreign->id.'/status', ['status' => 'cancelled'])->assertNotFound();
        $this->postJson('/api/couple-challenges/'.$foreign->id.'/complete')->assertNotFound();
        $this->getJson('/api/couple-challenges/'.$foreign->id.'/media/'.$foreign->id)->assertNotFound();
        $this->getJson('/api/couple-challenges')->assertOk()->assertJsonCount(0, 'data.items');
        $challenge = Challenge::factory()->create();
        $joined = $this->join($challenge);
        $this->postJson('/api/challenges/'.$challenge->id.'/start', ['points' => 999])->assertUnprocessable();
        $this->postJson('/api/couple-challenges/'.$joined['id'].'/complete', ['points' => 999, 'completed_by_partner' => 'partner_two', 'completed_at' => '2000-01-01', 'level' => 5])->assertUnprocessable()->assertJsonValidationErrors(['points', 'completed_by_partner', 'completed_at', 'level']);
        $this->getJson('/api/challenges/progress')->assertOk()->assertJsonPath('data.total_points', 0);
        $this->postJson('/api/device/revoke')->assertOk();
        $this->getJson('/api/challenges')->assertUnauthorized();
        $this->postJson('/api/couple-challenges/'.$joined['id'].'/complete')->assertUnauthorized();
    }

    public function test_proof_photos_are_private_and_retries_do_not_replace_the_original(): void
    {
        Storage::fake('local');
        $one = $this->token();
        $two = $this->token('partner_two');
        $this->withToken($one);
        $joined = $this->join(Challenge::factory()->create());
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6pFYAAAAASUVORK5CYII=');
        $photo = fn () => UploadedFile::fake()->createWithContent('proof.png', $bytes);
        $path = '/api/couple-challenges/'.$joined['id'];
        $this->postJson($path.'/complete', ['proof_photo' => $photo()])->assertOk()->assertJsonCount(1, 'data.media');
        $saved = CoupleChallenge::findOrFail($joined['id']);
        Storage::disk('local')->assertExists($saved->proof_photo);
        $url = $path.'/media/'.$joined['id'];
        $this->withToken($two)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->postJson($path.'/complete', ['proof_photo' => $photo()])->assertOk();
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->postJson($path.'/complete', ['proof_photo' => UploadedFile::fake()->image('different.jpg')])->assertConflict();
        $this->assertSame($saved->proof_photo, $saved->fresh()->proof_photo);
        $this->withToken('')->getJson($url)->assertUnauthorized();
        $foreign = CoupleChallenge::factory()->create();
        $this->withToken($one)->getJson('/api/couple-challenges/'.$foreign->id.'/media/'.$joined['id'])->assertNotFound();
    }

    public function test_upload_validation_and_paged_catalog_and_history(): void
    {
        $this->withToken($this->token());
        Challenge::factory()->count(13)->create(['category' => 'food']);
        $this->getJson('/api/challenges?category=food')->assertOk()->assertJsonCount(12, 'data.items')->assertJsonPath('data.pagination.last_page', 2);
        $this->getJson('/api/challenges?category=food&page=2')->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson('/api/challenges?difficulty=hard')->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson('/api/challenges?category=invalid')->assertUnprocessable();
        $this->getJson('/api/couple-challenges?status=invalid')->assertUnprocessable();
        $joined = $this->join(Challenge::firstOrFail());
        $path = '/api/couple-challenges/'.$joined['id'].'/complete';
        $this->postJson($path, ['proof_photo' => UploadedFile::fake()->image('huge.png')->size(5121)])->assertUnprocessable();
        $this->postJson($path, ['proof_photo' => UploadedFile::fake()->create('script.svg', 1, 'image/svg+xml')])->assertUnprocessable();
        CoupleChallenge::factory()->count(12)->create(['couple_id' => 1]);
        $this->getJson('/api/couple-challenges')->assertOk()->assertJsonCount(12, 'data.items')->assertJsonPath('data.pagination.total', 13);
        $this->getJson('/api/couple-challenges?page=2')->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_broadcast_targets_only_active_devices_and_never_contains_proof_or_reward_data(): void
    {
        $token = $this->token();
        $this->token('partner_two');
        $event = new ChallengesChanged(1);
        $this->assertCount(2, $event->broadcastOn());
        $this->assertSame(['kind' => 'progress'], $event->broadcastWith());
        $this->withToken($token)->postJson('/api/device/revoke')->assertOk();
        $this->assertCount(1, $event->broadcastOn());
    }
}
