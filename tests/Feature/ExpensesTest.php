<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CoupleProfile;
use App\Models\Expense;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpensesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function token(string $partner = 'partner_one'): string
    {
        CoupleProfile::query()->first() ?? CoupleProfile::factory()->create(['id' => 1]);

        return $this->postJson('/api/device/register', ['code' => 'test-recovery-code', 'partner_type' => $partner, 'device_name' => 'Expenses test', 'device_identifier' => (string) Str::uuid()])->assertCreated()->json('data.token');
    }

    private function input(array $overrides = []): array
    {
        return array_replace(['description' => 'Dinner together', 'amount' => '123.45', 'category' => 'food', 'paid_by' => 'partner_two', 'expense_date' => '2026-10-11', 'notes' => 'For our anniversary'], $overrides);
    }

    public function test_both_partners_can_create_read_update_trash_and_restore_shared_expenses(): void
    {
        $one = $this->token();
        $two = $this->token('partner_two');
        $id = $this->withToken($one)->postJson('/api/expenses', $this->input())->assertCreated()->assertJsonPath('data.amount', '123.45')->assertJsonPath('data.paid_by', 'partner_two')->assertJsonPath('data.currency', 'PHP')->json('data.id');
        $this->assertDatabaseHas('expenses', ['id' => $id, 'couple_id' => 1, 'amount' => 12345, 'paid_by' => 'partner_two']);
        $this->withToken($two)->getJson('/api/expenses/'.$id)->assertOk()->assertJsonPath('data.description', 'Dinner together');
        $updated = $this->input(['description' => 'Changed dinner', 'amount' => '0.01', 'paid_by' => 'partner_one', 'notes' => null]);
        $this->putJson('/api/expenses/'.$id, $updated)->assertOk()->assertJsonPath('data.amount', '0.01')->assertJsonPath('data.notes', null);
        $activities = ActivityLog::query()->count();
        $this->putJson('/api/expenses/'.$id, $updated)->assertOk();
        $this->assertDatabaseCount('activity_logs', $activities);
        $this->deleteJson('/api/expenses/'.$id)->assertOk();
        $this->getJson('/api/expenses/'.$id)->assertNotFound();
        $this->getJson('/api/expenses?month=2026-10')->assertOk()->assertJsonPath('data.summary.total', '0.00')->assertJsonCount(0, 'data.items');
        $this->getJson('/api/expenses?month=2026-10&trash=1')->assertOk()->assertJsonCount(1, 'data.items');
        $this->withToken($one)->postJson('/api/expenses/'.$id.'/restore')->assertOk();
        $this->getJson('/api/expenses?month=2026-10')->assertOk()->assertJsonPath('data.summary.total', '0.01');
    }

    public function test_money_is_exact_and_monthly_totals_are_scoped_and_independent_of_list_filters(): void
    {
        $this->withToken($this->token());
        foreach ([
            ['amount' => '0.10', 'expense_date' => '2026-10-01', 'paid_by' => 'partner_one', 'category' => 'food'],
            ['amount' => '0.20', 'expense_date' => '2026-10-31', 'paid_by' => 'partner_two', 'category' => 'gifts'],
            ['amount' => '99999999.99', 'expense_date' => '2026-10-15', 'paid_by' => 'partner_one', 'category' => 'food'],
            ['amount' => '50.00', 'expense_date' => '2026-09-30'],
            ['amount' => '60.00', 'expense_date' => '2026-11-01'],
        ] as $data) {
            $this->postJson('/api/expenses', $this->input($data))->assertCreated();
        }
        Expense::factory()->create(['couple_id' => 1, 'amount' => 999999, 'deleted_at' => now()]);
        Expense::factory()->create(['amount' => 888888]);
        $summary = $this->getJson('/api/expenses?month=2026-10&category=food&paid_by=partner_one')->assertOk()->assertJsonCount(2, 'data.items')->assertJsonPath('data.summary.total', '100000000.29')->assertJsonPath('data.summary.partners.partner_one', '100000000.09')->assertJsonPath('data.summary.partners.partner_two', '0.20')->assertJsonPath('data.summary.count', 3)->json('data.summary');
        $this->assertCount(8, $summary['categories']);
        $this->assertSame('100000000.09', $summary['categories'][0]['amount']);
        $this->getJson('/api/expenses?month=2026-11')->assertOk()->assertJsonPath('data.summary.total', '60.00');
        $this->getJson('/api/expenses?month=2026-12')->assertOk()->assertJsonPath('data.summary.total', '0.00')->assertJsonPath('data.summary.count', 0)->assertJsonPath('data.summary.categories.0.percent', 0);
    }

    public function test_invalid_money_dates_categories_payers_and_forged_ownership_are_rejected(): void
    {
        $this->withToken($this->token());
        foreach (['0', '0.0', '0.00', '-1', '1.001', '1e3', '01.00', '100000000', '1,000', 'NaN', '1.', 123.45] as $amount) {
            $this->postJson('/api/expenses', $this->input(['amount' => $amount]))->assertUnprocessable()->assertJsonValidationErrors('amount');
        }
        foreach ([
            ['description' => ' '], ['category' => 'crypto'], ['paid_by' => 'both'], ['expense_date' => '2026-02-30'], ['expense_date' => '1899-12-31'], ['expense_date' => '2101-01-01'], ['notes' => str_repeat('x', 10001)], ['couple_id' => 99], ['partner_type' => 'partner_one'], ['currency' => 'USD'], ['deleted_at' => '2026-01-01'],
        ] as $input) {
            $this->postJson('/api/expenses', $this->input($input))->assertUnprocessable();
        }
        $this->assertDatabaseCount('expenses', 0);
        foreach (['month=2026-13', 'month=2026-2', 'month=1899-12', 'month=2101-01', 'category=bad', 'paid_by=both', 'page=0', 'trash=maybe'] as $filter) {
            $this->getJson('/api/expenses?'.$filter)->assertUnprocessable();
        }
        $this->postJson('/api/expenses', $this->input(['amount' => ' 1.20 ']))->assertCreated()->assertJsonPath('data.amount', '1.20');
    }

    public function test_missing_foreign_and_revoked_credentials_cannot_read_or_change_expenses(): void
    {
        $this->getJson('/api/expenses')->assertUnauthorized();
        $this->postJson('/api/expenses', $this->input())->assertUnauthorized();
        $token = $this->token();
        $foreign = Expense::factory()->create();
        $this->withToken($token);
        $this->getJson('/api/expenses?month=2026-10')->assertOk()->assertJsonPath('data.summary.total', '0.00')->assertJsonCount(0, 'data.items');
        $this->getJson('/api/expenses/'.$foreign->id)->assertNotFound();
        $this->putJson('/api/expenses/'.$foreign->id, $this->input())->assertNotFound();
        $this->deleteJson('/api/expenses/'.$foreign->id)->assertNotFound();
        $foreign->delete();
        $this->postJson('/api/expenses/'.$foreign->id.'/restore')->assertNotFound();
        $id = $this->postJson('/api/expenses', $this->input())->assertCreated()->json('data.id');
        $this->postJson('/api/device/revoke')->assertOk();
        $this->getJson('/api/expenses')->assertUnauthorized();
        $this->getJson('/api/expenses/'.$id)->assertUnauthorized();
        $this->putJson('/api/expenses/'.$id, $this->input())->assertUnauthorized();
        $this->deleteJson('/api/expenses/'.$id)->assertUnauthorized();
    }

    public function test_pagination_month_boundaries_and_phone_replacement_preserve_records(): void
    {
        $one = $this->token();
        $this->withToken($one);
        Expense::factory()->count(21)->create(['couple_id' => 1]);
        Expense::factory()->create(['couple_id' => 1, 'expense_date' => '2024-02-29', 'amount' => 10]);
        Expense::factory()->create(['couple_id' => 1, 'expense_date' => '2024-03-01', 'amount' => 20]);
        $this->getJson('/api/expenses?month=2026-10')->assertOk()->assertJsonCount(20, 'data.items')->assertJsonPath('data.pagination.total', 21)->assertJsonPath('data.summary.total', '2592.45');
        $this->getJson('/api/expenses?month=2026-10&page=2')->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson('/api/expenses?month=2024-02')->assertOk()->assertJsonPath('data.summary.total', '0.10')->assertJsonCount(1, 'data.items');
        $replacement = $this->postJson('/api/device/replace', ['code' => 'test-recovery-code', 'partner_type' => 'partner_one', 'device_name' => 'Replacement', 'device_identifier' => (string) Str::uuid()])->assertCreated()->json('data.token');
        $this->getJson('/api/expenses')->assertUnauthorized();
        $this->withToken($replacement)->getJson('/api/expenses?month=2026-10')->assertOk()->assertJsonPath('data.pagination.total', 21);
    }

    public function test_default_month_respects_the_shared_timezone(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-30T16:30:00Z'));
        $this->withToken($this->token());
        $this->getJson('/api/expenses')->assertOk()->assertJsonPath('data.summary.month', '2026-10')->assertJsonPath('data.today', '2026-10-01');
    }
}
