<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CoupleProfile;
use App\Models\Note;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function token(string $partner = 'partner_one'): string
    {
        CoupleProfile::query()->first() ?? CoupleProfile::factory()->create(['id' => 1]);

        return $this->postJson('/api/device/register', ['code' => 'test-recovery-code', 'partner_type' => $partner, 'device_name' => 'Notes test', 'device_identifier' => (string) Str::uuid()])->assertCreated()->json('data.token');
    }

    private function input(array $overrides = []): array
    {
        return array_replace(['title' => 'A thought', 'content' => 'Some words to keep.', 'visibility' => 'private', 'pinned' => false], $overrides);
    }

    public function test_private_notes_are_visible_only_to_their_permanent_partner_for_every_endpoint(): void
    {
        $one = $this->token();
        $two = $this->token('partner_two');
        $id = $this->withToken($one)->postJson('/api/notes', $this->input(['title' => 'Secret sentinel', 'content' => 'Private content sentinel']))->assertCreated()->assertJsonPath('data.visibility', 'partner_one_private')->assertJsonPath('data.created_by_partner', 'partner_one')->json('data.id');
        $this->withToken($two)->postJson('/api/notes', $this->input())->assertCreated()->assertJsonPath('data.visibility', 'partner_two_private');
        $this->getJson('/api/notes/'.$id)->assertNotFound();
        $this->putJson('/api/notes/'.$id, $this->input())->assertNotFound();
        $this->patchJson('/api/notes/'.$id.'/pin', ['pinned' => true])->assertNotFound();
        $this->deleteJson('/api/notes/'.$id)->assertNotFound();
        $this->postJson('/api/notes/'.$id.'/restore')->assertNotFound();
        $this->getJson('/api/notes?q=sentinel')->assertOk()->assertJsonCount(0, 'data.items')->assertJsonPath('data.pagination.total', 0)->assertJsonPath('data.summary.private', 1)->assertJsonPath('data.summary.pinned', 0);
        $this->withToken($one)->getJson('/api/notes/'.$id)->assertOk()->assertJsonPath('data.content', 'Private content sentinel');
        $this->deleteJson('/api/notes/'.$id)->assertOk();
        $this->withToken($two)->getJson('/api/notes?trash=1')->assertOk()->assertJsonCount(0, 'data.items')->assertJsonPath('data.summary.private', 0);
        $this->postJson('/api/notes/'.$id.'/restore')->assertNotFound();
        $this->withToken($one)->getJson('/api/notes?trash=1')->assertOk()->assertJsonCount(1, 'data.items');
        $this->postJson('/api/notes/'.$id.'/restore')->assertOk();
    }

    public function test_shared_notes_can_be_managed_by_both_partners_and_creator_is_immutable(): void
    {
        $one = $this->token();
        $two = $this->token('partner_two');
        $id = $this->withToken($one)->postJson('/api/notes', $this->input(['visibility' => 'shared']))->assertCreated()->json('data.id');
        $this->withToken($two)->getJson('/api/notes/'.$id)->assertOk();
        $input = $this->input(['visibility' => 'shared', 'title' => 'Updated title', 'content' => 'New words']);
        $this->putJson('/api/notes/'.$id, $input)->assertOk()->assertJsonPath('data.created_by_partner', 'partner_one')->assertJsonPath('data.content', 'New words');
        $logs = ActivityLog::query()->count();
        $this->putJson('/api/notes/'.$id, $input)->assertOk();
        $this->assertDatabaseCount('activity_logs', $logs);
        $this->patchJson('/api/notes/'.$id.'/pin', ['pinned' => true])->assertOk()->assertJsonPath('data.pinned', true);
        $this->patchJson('/api/notes/'.$id.'/pin', ['pinned' => true])->assertOk();
        $this->assertDatabaseCount('activity_logs', $logs + 1);
        $this->deleteJson('/api/notes/'.$id)->assertOk();
        $this->getJson('/api/notes/'.$id)->assertNotFound();
        $this->withToken($one)->postJson('/api/notes/'.$id.'/restore')->assertOk();
    }

    public function test_private_changes_never_create_shared_activity_or_expose_titles_on_home(): void
    {
        $one = $this->token();
        $two = $this->token('partner_two');
        $logs = ActivityLog::query()->count();
        $id = $this->withToken($one)->postJson('/api/notes', $this->input(['title' => 'Hidden existence', 'content' => 'Hidden content']))->assertCreated()->json('data.id');
        $this->putJson('/api/notes/'.$id, $this->input(['title' => 'Still private', 'pinned' => true]))->assertOk();
        $this->patchJson('/api/notes/'.$id.'/pin', ['pinned' => false])->assertOk();
        $this->deleteJson('/api/notes/'.$id)->assertOk();
        $this->postJson('/api/notes/'.$id.'/restore')->assertOk();
        $this->assertDatabaseCount('activity_logs', $logs);
        $home = $this->withToken($two)->getJson('/api/dashboard')->assertOk()->getContent();
        $this->assertStringNotContainsString('Hidden', $home);
        $this->assertStringNotContainsString('note_', $home);
    }

    public function test_visibility_cannot_be_changed_or_forged_to_target_the_other_partner(): void
    {
        $this->withToken($this->token());
        $unicode = str_repeat('🙂', 19960)."\n<script>Just words</script>";
        $unicodeId = $this->postJson('/api/notes', $this->input(['content' => $unicode]))->assertCreated()->assertJsonPath('data.content', $unicode)->json('data.id');
        $this->getJson('/api/notes/'.$unicodeId)->assertOk()->assertJsonPath('data.content', $unicode);
        $id = $this->postJson('/api/notes', $this->input())->assertCreated()->json('data.id');
        $this->putJson('/api/notes/'.$id, $this->input(['visibility' => 'shared']))->assertConflict();
        $this->getJson('/api/notes/'.$id)->assertOk()->assertJsonPath('data.visibility', 'partner_one_private');
        $shared = $this->postJson('/api/notes', $this->input(['visibility' => 'shared']))->assertCreated()->json('data.id');
        $this->putJson('/api/notes/'.$shared, $this->input())->assertConflict();
        foreach ([
            ['visibility' => 'partner_two_private'], ['visibility' => 'partner_one_private'], ['created_by_partner' => 'partner_two'], ['couple_id' => 9], ['partner_type' => 'partner_two'], ['deleted_at' => '2026-01-01'], ['title' => ' '], ['content' => ' '], ['content' => str_repeat('x', 20001)], ['title' => str_repeat('x', 201)], ['pinned' => 'yes'],
        ] as $data) {
            $this->postJson('/api/notes', $this->input($data))->assertUnprocessable();
        }
        $this->assertDatabaseCount('notes', 3);
        $this->patchJson('/api/notes/'.$id.'/pin', ['pinned' => true, 'visibility' => 'shared'])->assertUnprocessable();
    }

    public function test_search_pagination_filters_and_pinned_order_never_include_inaccessible_notes(): void
    {
        $this->withToken($this->token());
        Note::factory()->count(21)->create(['couple_id' => 1, 'visibility' => 'partner_one_private']);
        $pinned = Note::factory()->create(['couple_id' => 1, 'title' => '100%_!plan', 'pinned' => true]);
        Note::factory()->create(['couple_id' => 1, 'title' => '100%_!plan', 'visibility' => 'partner_two_private', 'created_by_partner' => 'partner_two', 'pinned' => true]);
        Note::factory()->create(['title' => '100%_!plan', 'pinned' => true]);
        $this->getJson('/api/notes')->assertOk()->assertJsonCount(20, 'data.items')->assertJsonPath('data.items.0.id', $pinned->id)->assertJsonPath('data.pagination.total', 22)->assertJsonPath('data.summary.shared', 1)->assertJsonPath('data.summary.private', 21)->assertJsonPath('data.summary.pinned', 1);
        $this->getJson('/api/notes?page=2')->assertOk()->assertJsonCount(2, 'data.items');
        $this->getJson('/api/notes?visibility=shared')->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson('/api/notes?visibility=private&pinned=0')->assertOk()->assertJsonPath('data.pagination.total', 21);
        $this->getJson('/api/notes?q='.urlencode('%_!'))->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $pinned->id);
        foreach (['visibility=partner_two_private', 'pinned=yes', 'page=0', 'trash=bad'] as $filter) {
            $this->getJson('/api/notes?'.$filter)->assertUnprocessable();
        }
    }

    public function test_foreign_and_revoked_devices_cannot_read_or_modify_any_notes_and_recovery_preserves_privacy(): void
    {
        $this->getJson('/api/notes')->assertUnauthorized();
        $this->postJson('/api/notes', $this->input())->assertUnauthorized();
        $one = $this->token();
        $two = $this->token('partner_two');
        $this->withToken($one);
        $foreign = Note::factory()->create();
        $this->getJson('/api/notes/'.$foreign->id)->assertNotFound();
        $this->putJson('/api/notes/'.$foreign->id, $this->input(['visibility' => 'shared']))->assertNotFound();
        $this->patchJson('/api/notes/'.$foreign->id.'/pin', ['pinned' => true])->assertNotFound();
        $this->deleteJson('/api/notes/'.$foreign->id)->assertNotFound();
        $foreign->delete();
        $this->postJson('/api/notes/'.$foreign->id.'/restore')->assertNotFound();
        $id = $this->postJson('/api/notes', $this->input())->assertCreated()->json('data.id');
        $replacement = $this->postJson('/api/device/replace', ['code' => 'test-recovery-code', 'partner_type' => 'partner_one', 'device_name' => 'Replacement', 'device_identifier' => (string) Str::uuid()])->assertCreated()->json('data.token');
        $this->getJson('/api/notes')->assertUnauthorized();
        $this->getJson('/api/notes/'.$id)->assertUnauthorized();
        $this->putJson('/api/notes/'.$id, $this->input())->assertUnauthorized();
        $this->patchJson('/api/notes/'.$id.'/pin', ['pinned' => true])->assertUnauthorized();
        $this->deleteJson('/api/notes/'.$id)->assertUnauthorized();
        $this->postJson('/api/notes/'.$id.'/restore')->assertUnauthorized();
        $this->withToken($replacement)->getJson('/api/notes/'.$id)->assertOk()->assertJsonPath('data.visibility', 'partner_one_private');
        $this->withToken($two)->getJson('/api/notes/'.$id)->assertNotFound();
    }
}
