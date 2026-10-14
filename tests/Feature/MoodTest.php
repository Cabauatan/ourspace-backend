<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CoupleProfile;
use App\Models\Mood;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MoodTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function token(string $partner = 'partner_one'): string
    {
        CoupleProfile::query()->first() ?? CoupleProfile::factory()->create(['id' => 1]);

        return $this->postJson('/api/device/register', ['code' => 'test-recovery-code', 'partner_type' => $partner, 'device_name' => 'Mood test', 'device_identifier' => (string) Str::uuid()])->assertCreated()->json('data.token');
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['mood' => 'happy', 'note' => 'A lovely morning', 'mood_date' => now(config('tasks.timezone'))->toDateString()], $extra);
    }

    public function test_each_partner_edits_only_own_daily_entry_and_identical_saves_do_not_duplicate_activity(): void
    {
        $one = $this->token();
        $two = $this->token('partner_two');
        $id = $this->withToken($one)->postJson('/api/moods', $this->payload())->assertCreated()->assertJsonPath('data.partner_type', 'partner_one')->json('data.id');
        $count = ActivityLog::count();
        $this->postJson('/api/moods', $this->payload())->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame($count, ActivityLog::count());
        $this->postJson('/api/moods', $this->payload(['mood' => 'tired', 'note' => null]))->assertOk()->assertJsonPath('data.note', null)->assertJsonPath('data.id', $id);
        $this->withToken($two)->postJson('/api/moods', $this->payload(['mood' => 'loved']))->assertCreated()->assertJsonPath('data.partner_type', 'partner_two');
        $this->getJson('/api/moods/today')->assertOk()->assertJsonCount(2, 'data.items')->assertJsonPath('data.items.0.mood', 'tired')->assertJsonPath('data.items.1.mood', 'loved');
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.moods.items.1.mood', 'loved');
        $this->assertDatabaseCount('moods', 2);
    }

    public function test_invalid_or_forged_input_and_past_future_dates_cannot_modify_entries(): void
    {
        $this->withToken($this->token());
        $this->postJson('/api/moods', [])->assertUnprocessable()->assertJsonValidationErrors(['mood', 'mood_date']);
        $this->postJson('/api/moods', $this->payload(['mood' => 'invalid', 'note' => str_repeat('x', 2001), 'mood_date' => '2027-02-30', 'couple_id' => 99, 'partner_type' => 'partner_two', 'id' => 99]))->assertUnprocessable()->assertJsonValidationErrors(['mood', 'note', 'mood_date', 'couple_id', 'partner_type', 'id']);
        foreach ([now(config('tasks.timezone'))->subDay()->toDateString(), now(config('tasks.timezone'))->addDay()->toDateString()] as $day) {
            $this->postJson('/api/moods', $this->payload(['mood_date' => $day]))->assertUnprocessable()->assertJsonValidationErrors('mood_date');
        }
        $this->getJson('/api/moods?month=2027-13&partner=invalid&page=0&couple_id=99')->assertUnprocessable()->assertJsonValidationErrors(['month', 'partner', 'page', 'couple_id']);
        $this->assertDatabaseCount('moods', 0);
    }

    public function test_shared_midnight_creates_a_new_day_without_overwriting_previous_history(): void
    {
        config(['tasks.timezone' => 'Asia/Singapore']);
        $this->travelTo(now()->setDate(2027, 1, 1)->setTime(15, 59));
        $this->withToken($this->token());
        $this->getJson('/api/moods/today')->assertOk()->assertJsonPath('data.today', '2027-01-01')->assertJsonPath('data.timezone', 'Asia/Singapore')->assertJsonCount(0, 'data.items');
        $old = $this->payload();
        $this->postJson('/api/moods', $old)->assertCreated();
        $this->travelTo(now()->addMinutes(2));
        $this->getJson('/api/moods/today')->assertOk()->assertJsonPath('data.today', '2027-01-02')->assertJsonCount(0, 'data.items');
        $this->postJson('/api/moods', $old)->assertUnprocessable()->assertJsonValidationErrors('mood_date');
        $this->postJson('/api/moods', $this->payload(['mood' => 'neutral']))->assertCreated();
        $this->getJson('/api/moods?month=2027-01')->assertOk()->assertJsonPath('data.items.0.mood_date', '2027-01-02')->assertJsonPath('data.items.1.mood', 'happy');
        $this->assertDatabaseCount('moods', 2);
    }

    public function test_history_pagination_and_monthly_counts_cover_the_full_filtered_month(): void
    {
        $this->withToken($this->token());
        for ($day = 1; $day <= 25; $day++) {
            Mood::factory()->create(['couple_id' => 1, 'mood_date' => sprintf('2027-02-%02d', $day), 'mood' => $day <= 20 ? 'happy' : 'tired']);
        }
        Mood::factory()->create(['couple_id' => 1, 'partner_type' => 'partner_two', 'mood_date' => '2027-02-20', 'mood' => 'loved']);
        Mood::factory()->create(['couple_id' => 1, 'mood_date' => '2027-02-28', 'mood' => 'happy']);
        Mood::factory()->create(['couple_id' => 1, 'mood_date' => '2027-03-01', 'mood' => 'sad']);
        $first = $this->getJson('/api/moods?month=2027-02')->assertOk()->assertJsonCount(20, 'data.items')->assertJsonPath('data.pagination.total', 27)->assertJsonPath('data.statistics.partner_one.total', 26)->assertJsonPath('data.statistics.partner_one.counts.happy', 21)->assertJsonPath('data.statistics.partner_one.counts.tired', 5)->assertJsonPath('data.statistics.partner_one.counts.sad', 0)->assertJsonPath('data.statistics.partner_two.total', 1)->json('data.items');
        $second = $this->getJson('/api/moods?month=2027-02&page=2')->assertOk()->assertJsonCount(7, 'data.items')->assertJsonPath('data.statistics.partner_one.total', 26)->json('data.items');
        $this->assertEmpty(array_intersect(array_column($first, 'id'), array_column($second, 'id')));
        $this->getJson('/api/moods?month=2027-02&partner=partner_two')->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.statistics.partner_one.total', 0)->assertJsonPath('data.statistics.partner_two.counts.loved', 1);
    }

    public function test_foreign_couple_moods_never_appear_in_daily_history_statistics_or_dashboard(): void
    {
        $this->withToken($this->token());
        $foreign = Mood::factory()->create(['mood' => 'sad']);
        $this->getJson('/api/moods/today')->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson('/api/moods')->assertOk()->assertJsonCount(0, 'data.items')->assertJsonPath('data.statistics.partner_one.total', 0);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonCount(0, 'data.moods.items');
        $this->postJson('/api/moods', $this->payload())->assertCreated()->assertJsonPath('data.partner_type', 'partner_one');
        $this->assertSame('sad', $foreign->fresh()->mood);
        $this->assertDatabaseCount('moods', 2);
    }

    public function test_missing_and_replaced_device_credentials_are_rejected_and_partner_moods_survive(): void
    {
        $this->getJson('/api/moods')->assertUnauthorized();
        $this->getJson('/api/moods/today')->assertUnauthorized();
        $this->postJson('/api/moods', $this->payload())->assertUnauthorized();
        $one = $this->token();
        $two = $this->token('partner_two');
        $this->withToken($one)->postJson('/api/moods', $this->payload())->assertCreated();
        $replacement = $this->postJson('/api/device/replace', ['code' => 'test-recovery-code', 'partner_type' => 'partner_one', 'device_name' => 'Replacement', 'device_identifier' => (string) Str::uuid()])->assertCreated()->json('data.token');
        $this->getJson('/api/moods')->assertUnauthorized();
        $this->getJson('/api/moods/today')->assertUnauthorized();
        $this->postJson('/api/moods', $this->payload())->assertUnauthorized();
        $this->withToken($two)->getJson('/api/moods/today')->assertOk()->assertJsonPath('data.items.0.mood', 'happy');
        $this->withToken($replacement)->postJson('/api/moods', $this->payload(['mood' => 'excited']))->assertOk()->assertJsonPath('data.partner_type', 'partner_one');
        $this->assertDatabaseCount('moods', 1);
    }
}
