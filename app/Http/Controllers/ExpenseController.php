<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Services\ExpenseService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    private function result(Request $request, Expense $expense, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => (new ExpenseResource($expense))->resolve($request)], $status);
    }

    public function index(Request $request, ExpenseService $service): JsonResponse
    {
        $input = $request->validate(['month' => ['nullable', 'date_format:Y-m', 'after_or_equal:1900-01', 'before_or_equal:2100-12'], 'category' => ['nullable', Rule::in(config('expenses.categories'))], 'paid_by' => ['nullable', Rule::in(['partner_one', 'partner_two'])], 'trash' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1']]);
        $month = $input['month'] ?? now(config('expenses.timezone'))->format('Y-m');
        $query = Expense::query()->where('couple_id', $request->user()->couple_id)->where('expense_date', '>=', $month.'-01')->where('expense_date', '<', CarbonImmutable::createFromFormat('!Y-m', $month)->addMonth()->toDateString());
        if ($request->boolean('trash')) {
            $query->onlyTrashed();
        }
        foreach (['category', 'paid_by'] as $field) {
            if (! empty($input[$field])) {
                $query->where($field, $input[$field]);
            }
        }
        $page = $query->orderByDesc('expense_date')->orderByDesc('id')->paginate(20);

        return response()->json(['success' => true, 'message' => 'Your shared expenses.', 'data' => ['items' => ExpenseResource::collection($page->items())->resolve($request), 'pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()], 'summary' => $service->summary($request->user()->couple_id, $month), 'today' => now(config('expenses.timezone'))->toDateString(), 'timezone' => config('expenses.timezone')]]);
    }

    public function show(Request $request, int $expense, ExpenseService $service): JsonResponse
    {
        return $this->result($request, $service->find($request->user(), $expense), 'Your expense.');
    }

    public function store(SaveExpenseRequest $request, ExpenseService $service): JsonResponse
    {
        return $this->result($request, $service->save($request->user(), $request->validated()), 'Expense added.', 201);
    }

    public function update(SaveExpenseRequest $request, int $expense, ExpenseService $service): JsonResponse
    {
        return $this->result($request, $service->save($request->user(), $request->validated(), $expense), 'Expense updated.');
    }

    public function destroy(Request $request, int $expense, ExpenseService $service): JsonResponse
    {
        $service->trash($request->user(), $expense);

        return response()->json(['success' => true, 'message' => 'Expense moved to trash.', 'data' => null]);
    }

    public function restore(Request $request, int $expense, ExpenseService $service): JsonResponse
    {
        return $this->result($request,$service->trash($request->user(),$expense,true),'Expense restored.');
    }
}
