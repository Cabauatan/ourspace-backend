<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CalendarEvent;
use App\Models\CoupleProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function token(string $partner = 'partner_one'): string
    {
        CoupleProfile::query()->first() ?? CoupleProfile::factory()->create(['id' => 1, 'anniversary_date' => null]);

        return $this->postJson('/api/device/register', ['code' => 'test-recovery-code', 'partner_type' => $partner, 'device_name' => 'Calendar test', 'device_identifier' => (string) Str::uuid()])->assertCreated()->json('data.token');
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['title' => 'Our trip', 'description' => 'Time together', 'event_type' => 'travel', 'start_date' => '2027-04-29', 'end_date' => '2027-05-02', 'reminder_at' => null], $extra);
    }

    public function test_partners_share_edits_trash_and_restore_without_changing_creator(): void
    {
        $one = $this->token();
        $two = $this->token('partner_two');
        $id = $this->withToken($one)->postJson('/api/calendar', $this->payload())->assertCreated()->json('data.id');
        $this->withToken($two)->getJson('/api/calendar/'.$id)->assertOk()->assertJsonPath('data.created_by_partner', 'partner_one');
        $this->putJson('/api/calendar/'.$id, $this->payload(['title' => 'Tickets booked']))->assertOk();
        $count = ActivityLog::count();
        $this->putJson('/api/calendar/'.$id, $this->payload(['title' => 'Tickets booked']))->assertOk();
        $this->assertSame($count, ActivityLog::count());
        $this->deleteJson('/api/calendar/'.$id)->assertOk();
        $this->getJson('/api/calendar/'.$id)->assertNotFound();
        $this->getJson('/api/calendar?month=2027-05')->assertOk()->assertJsonCount(0, 'data.items');
        $this->withToken($one)->getJson('/api/calendar?trash=1')->assertOk()->assertJsonPath('data.items.0.title', 'Tickets booked');
        $this->postJson('/api/calendar/'.$id.'/restore')->assertOk()->assertJsonPath('data.created_by_partner', 'partner_one');
        $this->getJson('/api/calendar?month=2027-05')->assertOk()->assertJsonPath('data.items.0.id', $id);
    }

    public function test_month_overlap_filters_and_pagination_keep_all_events_accessible(): void
    {
        $this->withToken($this->token());
        $id = $this->postJson('/api/calendar', $this->payload())->assertCreated()->json('data.id');
        foreach (['2027-04', '2027-05'] as $month) {
            $this->getJson('/api/calendar?month='.$month)->assertOk()->assertJsonPath('data.items.0.id', $id);
        }
        $this->getJson('/api/calendar?month=2027-06')->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson('/api/calendar?month=2027-05&event_type=date')->assertOk()->assertJsonCount(0, 'data.items');
        for ($i = 0; $i < 100; $i++) {
            $event = new CalendarEvent($this->payload(['title' => 'Plan '.$i]));
            $event->couple_id = 1;
            $event->created_by_partner = 'partner_one';
            $event->save();
        }
        $a = $this->getJson('/api/calendar?month=2027-05')->assertOk()->assertJsonCount(100, 'data.items')->assertJsonPath('data.pagination.total', 101)->json('data.items');
        $b = $this->getJson('/api/calendar?month=2027-05&page=2')->assertOk()->assertJsonCount(1, 'data.items')->json('data.items');
        $this->assertEmpty(array_intersect(array_column($a, 'id'), array_column($b, 'id')));
    }

    public function test_anniversary_is_virtual_leap_safe_and_independent_of_upcoming_limit(): void
    {
        $this->travelTo(now()->setDate(2027, 2, 27)->setTime(12, 0));
        $this->withToken($this->token());
        CoupleProfile::query()->first()->update(['anniversary_date' => '2024-02-29']);
        $this->getJson('/api/calendar?month=2027-02')->assertOk()->assertJsonPath('data.items.0.start_date', '2027-02-28')->assertJsonPath('data.items.0.virtual', true);
        $this->getJson('/api/calendar?month=2028-02')->assertOk()->assertJsonPath('data.items.0.start_date', '2028-02-29');
        $this->getJson('/api/calendar?month=2023-02')->assertOk()->assertJsonCount(0, 'data.items');
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/calendar', $this->payload(['start_date' => '2027-02-27', 'end_date' => '2027-02-27']))->assertCreated();
        }
        $this->getJson('/api/calendar/upcoming')->assertOk()->assertJsonCount(10, 'data.items')->assertJsonPath('data.anniversary.start_date', '2027-02-28');
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.calendar.anniversary.start_date', '2027-02-28');
        $this->assertDatabaseCount('calendar_events', 10);
        $this->travelTo(now()->setDate(2027, 3, 1));
        $this->getJson('/api/calendar/upcoming')->assertOk()->assertJsonPath('data.anniversary.start_date', '2028-02-29');
        CoupleProfile::query()->first()->update(['anniversary_date' => '2030-01-01']);
        $this->getJson('/api/calendar/upcoming')->assertOk()->assertJsonPath('data.anniversary.start_date', '2030-01-01');
    }

    public function test_reminders_use_shared_timezone_and_dismissal_is_shared_idempotent_and_resets_only_for_changed_time(): void
    {
        config(['tasks.timezone' => 'Asia/Singapore']);
        $this->travelTo(now()->setDate(2027, 4, 29)->setTime(1, 0));
        $one = $this->token();
        $two = $this->token('partner_two');
        $payload = $this->payload(['reminder_at' => '2027-04-29T08:30']);
        $id = $this->withToken($one)->postJson('/api/calendar', $payload)->assertCreated()->assertJsonPath('data.reminder_local', '2027-04-29T08:30')->json('data.id');
        $this->assertSame('2027-04-29 00:30:00', CalendarEvent::findOrFail($id)->reminder_at->format('Y-m-d H:i:s'));
        $this->withToken($two)->getJson('/api/calendar/upcoming')->assertOk()->assertJsonPath('data.today', '2027-04-29')->assertJsonPath('data.due_reminders.0.id', $id);
        $dismissed = $this->patchJson('/api/calendar/'.$id.'/reminder')->assertOk()->json('data.reminder_dismissed_at');
        $this->assertNotNull($dismissed);
        $count = ActivityLog::count();
        $this->patchJson('/api/calendar/'.$id.'/reminder')->assertOk()->assertJsonPath('data.reminder_dismissed_at', $dismissed);
        $this->assertSame($count, ActivityLog::count());
        $this->withToken($one)->getJson('/api/dashboard')->assertOk()->assertJsonCount(0, 'data.calendar.due_reminders');
        $this->putJson('/api/calendar/'.$id, array_merge($payload, ['title' => 'Edited title']))->assertOk()->assertJsonPath('data.reminder_dismissed_at', $dismissed);
        $this->putJson('/api/calendar/'.$id, array_merge($payload, ['reminder_at' => '2027-04-29T08:45']))->assertOk()->assertJsonPath('data.reminder_dismissed_at', null);
        $this->getJson('/api/calendar/upcoming')->assertOk()->assertJsonCount(1, 'data.due_reminders');
        $this->putJson('/api/calendar/'.$id, array_merge($payload, ['reminder_at' => '2027-04-30T08:00']))->assertOk();
        $this->patchJson('/api/calendar/'.$id.'/reminder')->assertOk()->assertJsonPath('data.reminder_dismissed_at', null);
        $this->getJson('/api/calendar/upcoming')->assertOk()->assertJsonCount(0, 'data.due_reminders');
        $this->postJson('/api/calendar', $this->payload(['start_date' => '2027-04-28', 'end_date' => '2027-04-28', 'reminder_at' => '2027-04-28T08:00']))->assertCreated();
        $this->getJson('/api/calendar/upcoming')->assertOk()->assertJsonCount(0, 'data.due_reminders');
    }

    public function test_invalid_dates_reminders_filters_and_identity_are_rejected_atomically(): void
    {
        $this->withToken($this->token());
        $this->postJson('/api/calendar', $this->payload(['title' => '', 'event_type' => 'invalid', 'start_date' => '2027-02-30', 'couple_id' => 2, 'created_by_partner' => 'partner_two', 'reminder_dismissed_at' => 'fake']))->assertUnprocessable()->assertJsonValidationErrors(['title', 'event_type', 'start_date', 'couple_id', 'created_by_partner', 'reminder_dismissed_at']);
        $this->postJson('/api/calendar', $this->payload(['end_date' => '2027-04-28']))->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->postJson('/api/calendar', $this->payload(['reminder_at' => '2027-05-03T08:00']))->assertUnprocessable()->assertJsonValidationErrors('reminder_at');
        $this->getJson('/api/calendar?month=2027-13&event_type=invalid&trash=invalid&page=0&couple_id=99')->assertUnprocessable()->assertJsonValidationErrors(['month', 'event_type', 'trash', 'page', 'couple_id']);
        config(['tasks.timezone' => 'America/New_York']);
        $this->postJson('/api/calendar', $this->payload(['start_date' => '2027-03-14', 'end_date' => '2027-03-14', 'reminder_at' => '2027-03-14T02:30']))->assertUnprocessable()->assertJsonValidationErrors('reminder_at');
        $this->assertDatabaseCount('calendar_events', 0);
    }

    public function test_foreign_and_revoked_devices_cannot_access_events_but_shared_content_survives_replacement(): void
    {
        $this->getJson('/api/calendar')->assertUnauthorized();
        $one = $this->token();
        $two = $this->token('partner_two');
        $this->withToken($one);
        $other = CoupleProfile::factory()->create();
        $foreign = new CalendarEvent($this->payload());
        $foreign->couple_id = $other->id;
        $foreign->created_by_partner = 'partner_one';
        $foreign->save();
        $this->getJson('/api/calendar/'.$foreign->id)->assertNotFound();
        $this->putJson('/api/calendar/'.$foreign->id, $this->payload())->assertNotFound();
        $this->patchJson('/api/calendar/'.$foreign->id.'/reminder')->assertNotFound();
        $this->deleteJson('/api/calendar/'.$foreign->id)->assertNotFound();
        $foreign->delete();
        $this->postJson('/api/calendar/'.$foreign->id.'/restore')->assertNotFound();
        $this->getJson('/api/calendar?trash=1')->assertOk()->assertJsonCount(0, 'data.items');
        $id = $this->postJson('/api/calendar', $this->payload())->assertCreated()->json('data.id');
        $this->postJson('/api/device/replace', ['code' => 'test-recovery-code', 'partner_type' => 'partner_one', 'device_name' => 'Replacement', 'device_identifier' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/api/calendar')->assertUnauthorized();
        $this->getJson('/api/calendar/upcoming')->assertUnauthorized();
        $this->patchJson('/api/calendar/'.$id.'/reminder')->assertUnauthorized();
        $this->withToken($two)->getJson('/api/calendar/'.$id)->assertOk()->assertJsonPath('data.created_by_partner', 'partner_one');
    }
}
