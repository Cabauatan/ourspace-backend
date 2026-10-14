<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CoupleProfile;
use App\Models\SharedTask;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TasksTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function token(string $partner = 'partner_one'): string
    {
        CoupleProfile::query()->first() ?? CoupleProfile::factory()->create(['id' => 1]);

        return $this->postJson('/api/device/register', ['code' => 'test-recovery-code', 'partner_type' => $partner, 'device_name' => 'Task test phone', 'device_identifier' => (string) Str::uuid()])->assertCreated()->json('data.token');
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['title' => 'Buy flowers', 'description' => 'A small surprise', 'assigned_to' => 'both', 'priority' => 'medium', 'due_date' => '2026-10-09'], $extra);
    }

    public function test_tasks_are_shared_between_partners_and_status_changes_are_idempotent(): void
    {
        $one = $this->token();
        $two = $this->token('partner_two');
        $task = $this->withToken($one)->postJson('/api/tasks', $this->payload())->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.created_by_partner', 'partner_one')->json('data');
        $this->withToken($two)->getJson('/api/tasks')->assertOk()->assertJsonPath('data.items.0.id', $task['id']);
        $this->putJson('/api/tasks/'.$task['id'], $this->payload(['assigned_to' => 'partner_one', 'priority' => 'high', 'due_date' => null]))->assertOk()->assertJsonPath('data.created_by_partner', 'partner_one')->assertJsonPath('data.due_date', null);
        $this->patchJson('/api/tasks/'.$task['id'].'/status', ['status' => 'in_progress'])->assertOk()->assertJsonPath('data.completed_at', null);
        $completed = $this->patchJson('/api/tasks/'.$task['id'].'/status', ['status' => 'completed'])->assertOk()->json('data.completed_at');
        $this->assertNotNull($completed);
        $count = ActivityLog::count();
        $this->patchJson('/api/tasks/'.$task['id'].'/status', ['status' => 'completed'])->assertOk()->assertJsonPath('data.completed_at', $completed);
        $this->assertSame($count, ActivityLog::count());
        $this->putJson('/api/tasks/'.$task['id'], $this->payload())->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.completed_at', $completed);
        $this->patchJson('/api/tasks/'.$task['id'].'/status', ['status' => 'pending'])->assertOk()->assertJsonPath('data.completed_at', null);
        $this->assertDatabaseHas('tasks', ['id' => $task['id'], 'status' => 'pending', 'completed_at' => null]);
        $this->withToken($one)->getJson('/api/tasks/'.$task['id'])->assertOk()->assertJsonPath('data.assigned_to', 'both');
    }

    public function test_overdue_uses_shared_calendar_day_and_excludes_completed_undated_and_due_today(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08T17:00:00Z'));
        $this->withToken($this->token());
        $yesterday = $this->postJson('/api/tasks', $this->payload(['due_date' => '2026-10-08']))->assertCreated()->assertJsonPath('data.is_overdue', true)->json('data.id');
        $this->postJson('/api/tasks', $this->payload())->assertCreated()->assertJsonPath('data.is_overdue', false);
        $this->postJson('/api/tasks', $this->payload(['due_date' => null]))->assertCreated()->assertJsonPath('data.is_overdue', false);
        $this->getJson('/api/tasks?overdue=1')->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.today', '2026-10-09')->assertJsonPath('data.timezone', 'Asia/Singapore');
        $this->patchJson('/api/tasks/'.$yesterday.'/status', ['status' => 'completed'])->assertOk()->assertJsonPath('data.is_overdue', false);
        $this->getJson('/api/tasks?overdue=1')->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson('/api/tasks?overdue=0')->assertOk()->assertJsonCount(3, 'data.items');
        $this->travelBack();
    }

    public function test_filters_search_and_pagination_are_scoped_stable_and_literal(): void
    {
        $this->withToken($this->token());
        for ($i = 0; $i < 22; $i++) {
            $this->postJson('/api/tasks', $this->payload(['title' => 'Task '.$i]))->assertCreated();
        }
        $id = $this->postJson('/api/tasks', $this->payload(['title' => 'Save 50%_0', 'assigned_to' => 'partner_two', 'priority' => 'high']))->assertCreated()->json('data.id');
        $this->getJson('/api/tasks?q=%25_0&assigned_to=partner_two&priority=high&status=pending')->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $id);
        $this->getJson('/api/tasks?q=%27%20OR%201%3D1')->assertOk()->assertJsonCount(0, 'data.items');
        $pageOne = $this->getJson('/api/tasks')->assertOk()->assertJsonCount(20, 'data.items')->assertJsonPath('data.pagination.total', 23)->json('data.items');
        $pageTwo = $this->getJson('/api/tasks?page=2')->assertOk()->assertJsonCount(3, 'data.items')->json('data.items');
        $this->assertCount(0, array_intersect(array_column($pageOne, 'id'), array_column($pageTwo, 'id')));
    }

    public function test_trash_restore_and_dashboard_counts_preserve_the_task(): void
    {
        $this->withToken($this->token());
        $id = $this->postJson('/api/tasks', $this->payload(['due_date' => '2000-01-01']))->assertCreated()->json('data.id');
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.tasks.pending', 1)->assertJsonPath('data.tasks.overdue', 1);
        $this->deleteJson('/api/tasks/'.$id)->assertOk();
        $this->getJson('/api/tasks/'.$id)->assertNotFound();
        $this->getJson('/api/tasks')->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson('/api/tasks?trash=1')->assertOk()->assertJsonPath('data.items.0.id', $id);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.tasks.pending', 0);
        $this->postJson('/api/tasks/'.$id.'/restore')->assertOk()->assertJsonPath('data.assigned_to', 'both');
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.tasks.pending', 1)->assertJsonPath('data.recent_activity.0.type', 'task_restored');
        $count = ActivityLog::count();
        $this->putJson('/api/tasks/'.$id, $this->payload(['due_date' => '2000-01-01']))->assertOk();
        $this->assertSame($count, ActivityLog::count());
    }

    public function test_invalid_and_forged_fields_cannot_change_identity_or_completion(): void
    {
        $this->withToken($this->token());
        $this->postJson('/api/tasks', $this->payload(['title' => '', 'assigned_to' => 'device_1', 'priority' => 'urgent', 'due_date' => '2026-02-30', 'status' => 'completed', 'completed_at' => 'fake', 'couple_id' => 2, 'created_by_partner' => 'partner_two']))->assertUnprocessable()->assertJsonValidationErrors(['title', 'assigned_to', 'priority', 'due_date', 'status', 'completed_at', 'couple_id', 'created_by_partner']);
        $id = $this->postJson('/api/tasks', $this->payload())->assertCreated()->json('data.id');
        $this->patchJson('/api/tasks/'.$id.'/status', ['status' => 'invalid'])->assertUnprocessable();
        $this->patchJson('/api/tasks/'.$id.'/status', ['status' => 'completed', 'completed_at' => 'fake'])->assertUnprocessable();
        $this->getJson('/api/tasks?status=invalid&assigned_to=invalid&priority=invalid&overdue=invalid&page=0&couple_id=99')->assertUnprocessable()->assertJsonValidationErrors(['status', 'assigned_to', 'priority', 'overdue', 'page', 'couple_id']);
        $this->assertDatabaseHas('tasks', ['id' => $id, 'status' => 'pending', 'completed_at' => null]);
    }

    public function test_other_couple_and_revoked_phone_cannot_access_task_operations(): void
    {
        $this->getJson('/api/tasks')->assertUnauthorized();
        $this->postJson('/api/tasks', $this->payload())->assertUnauthorized();
        $one = $this->token();
        $two = $this->token('partner_two');
        $this->withToken($one);
        $other = CoupleProfile::factory()->create();
        $foreign = new SharedTask($this->payload());
        $foreign->couple_id = $other->id;
        $foreign->created_by_partner = 'partner_one';
        $foreign->status = 'pending';
        $foreign->save();
        $this->getJson('/api/tasks/'.$foreign->id)->assertNotFound();
        $this->putJson('/api/tasks/'.$foreign->id, $this->payload())->assertNotFound();
        $this->patchJson('/api/tasks/'.$foreign->id.'/status', ['status' => 'completed'])->assertNotFound();
        $this->deleteJson('/api/tasks/'.$foreign->id)->assertNotFound();
        $foreign->delete();
        $this->postJson('/api/tasks/'.$foreign->id.'/restore')->assertNotFound();
        $this->getJson('/api/tasks?trash=1')->assertOk()->assertJsonCount(0, 'data.items');
        $id = $this->postJson('/api/tasks', $this->payload())->assertCreated()->json('data.id');
        $this->postJson('/api/device/replace', ['code' => 'test-recovery-code', 'partner_type' => 'partner_one', 'device_name' => 'Replacement', 'device_identifier' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/api/tasks')->assertUnauthorized();
        $this->patchJson('/api/tasks/'.$id.'/status',['status' => 'completed'])->assertUnauthorized();
        $this->withToken($two)->getJson('/api/tasks/'.$id)->assertOk()->assertJsonPath('data.assigned_to','both');
    }
}
