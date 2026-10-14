<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CoupleProfile;
use App\Models\Device;
use App\Models\Expense;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ExpenseService
{
    public static function money(int $centavos): string
    {
        return intdiv($centavos, 100).'.'.str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function centavos(string $amount): int
    {
        $parts = explode('.', $amount);

        return ((int) $parts[0]) * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    private function lockActor(Device $actor): void
    {
        CoupleProfile::query()->whereKey($actor->couple_id)->lockForUpdate()->firstOrFail();
        abort_unless(Device::query()->whereKey($actor->id)->whereNull('revoked_at')->exists(), 401);
    }

    public function find(Device $actor, int $id): Expense
    {
        return Expense::query()->where('couple_id', $actor->couple_id)->findOrFail($id);
    }

    public function save(Device $actor, array $input, ?int $id = null): Expense
    {
        return DB::transaction(function () use ($actor, $input, $id): Expense {
            $this->lockActor($actor);
            $expense = $id ? $this->find($actor, $id) : new Expense;
            if (! $id) {
                $expense->couple_id = $actor->couple_id;
            }
            $input['amount'] = self::centavos($input['amount']);
            $input['notes'] = $input['notes'] ?? null;
            $expense->fill($input);
            $changed = $expense->isDirty();
            $expense->save();
            if ($changed) {
                ActivityLog::record($actor, $id ? 'expense_updated' : 'expense_created');
            }

            return $expense;
        });
    }

    public function trash(Device $actor, int $id, bool $restore = false): Expense
    {
        return DB::transaction(function () use ($actor, $id, $restore): Expense {
            $this->lockActor($actor);
            $query = Expense::query()->where('couple_id', $actor->couple_id);
            if ($restore) {
                $query->onlyTrashed();
            }
            $expense = $query->findOrFail($id);
            if ($restore) {
                $expense->restore();
            } else {
                $expense->delete();
            }
            ActivityLog::record($actor, $restore ? 'expense_restored' : 'expense_deleted');

            return $expense;
        });
    }

    public function summary(int $coupleId, string $month): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, config('expenses.timezone'));
        $rows = Expense::query()->where('couple_id', $coupleId)->where('expense_date', '>=', $start->toDateString())->where('expense_date', '<', $start->addMonth()->toDateString())->selectRaw('category, paid_by, SUM(amount) AS total, COUNT(*) AS entries')->groupBy('category', 'paid_by')->toBase()->get();
        $total = 0;
        $count = 0;
        $partners = ['partner_one' => 0, 'partner_two' => 0];
        $categories = array_fill_keys(config('expenses.categories'), 0);
        foreach ($rows as $row) {
            $value = (int) $row->total;
            $total += $value;
            $count += (int) $row->entries;
            $partners[$row->paid_by] += $value;
            $categories[$row->category] += $value;
        }

        return ['month' => $month, 'currency' => config('expenses.currency'), 'total' => self::money($total), 'count' => $count, 'partners' => array_map(fn (int $value) => self::money($value), $partners), 'categories' => array_map(fn (string $category, int $value) => ['category' => $category, 'amount' => self::money($value), 'percent' => $total ? round(100 * $value / $total,1) : 0], array_keys($categories), array_values($categories))];
    }
}
