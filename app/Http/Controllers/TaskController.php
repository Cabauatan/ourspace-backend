<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListTasksRequest;
use App\Http\Requests\SaveTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\SharedTask;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function index(ListTasksRequest $request): JsonResponse
    {
        $input = $request->validated();
        $base = SharedTask::query()->where('couple_id', $request->user()->couple_id);
        if ($request->boolean('trash')) {
            $base->onlyTrashed();
        }
        $query = clone $base;
        foreach (['status', 'assigned_to', 'priority'] as $key) {
            if (! empty($input[$key])) {
                $query->where($key, $input[$key]);
            }
        }
        if (isset($input['q']) && trim($input['q']) !== '') {
            $pattern = '%'.strtr(trim($input['q']), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->where(fn ($match) => $match->whereRaw("title LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("description LIKE ? ESCAPE '!'", [$pattern]));
        }
        if (isset($input['overdue'])) {
            if ($request->boolean('overdue')) {
                $query->where('status', '!=', 'completed')->where('due_date', '<', SharedTask::today());
            } else {
                $query->where(fn ($match) => $match->where('status', 'completed')->orWhereNull('due_date')->orWhere('due_date', '>=', SharedTask::today()));
            }
        }
        $page = $query->orderByRaw("CASE WHEN status = 'completed' THEN 1 ELSE 0 END")
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')->orderBy('due_date')
            ->orderByRaw("CASE priority WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")->orderByDesc('id')->paginate(20);

        return response()->json(['success' => true, 'message' => 'Your shared tasks.', 'data' => [
            'items' => TaskResource::collection($page->items())->resolve($request),
            'pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
            'summary' => ['pending' => (clone $base)->where('status', '!=', 'completed')->count(), 'completed' => (clone $base)->where('status', 'completed')->count(), 'overdue' => (clone $base)->where('status', '!=', 'completed')->where('due_date', '<', SharedTask::today())->count()],
            'today' => SharedTask::today(), 'timezone' => config('tasks.timezone'),
        ]]);
    }

    private function result(Request $request, SharedTask $task, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => (new TaskResource($task))->resolve($request)], $status);
    }

    public function show(Request $request, int $task): JsonResponse
    {
        return $this->result($request, SharedTask::query()->where('couple_id', $request->user()->couple_id)->findOrFail($task), 'Your task.');
    }

    public function store(SaveTaskRequest $request, TaskService $tasks): JsonResponse
    {
        return $this->result($request, $tasks->save($request->user(), $request->validated()), 'Task created.', 201);
    }

    public function update(SaveTaskRequest $request, int $task, TaskService $tasks): JsonResponse
    {
        return $this->result($request, $tasks->save($request->user(), $request->validated(), $task), 'Task updated.');
    }

    public function status(Request $request, int $task, TaskService $tasks): JsonResponse
    {
        $input = $request->validate(['status' => ['required', Rule::in(['pending', 'in_progress', 'completed'])], 'couple_id' => ['prohibited'], 'created_by_partner' => ['prohibited'], 'completed_at' => ['prohibited']]);

        return $this->result($request, $tasks->status($request->user(), $task, $input['status']), 'Task status updated.');
    }

    public function destroy(Request $request, int $task, TaskService $tasks): JsonResponse
    {
        $tasks->trash($request->user(), $task);

        return response()->json(['success' => true, 'message' => 'Task moved to trash.', 'data' => null]);
    }

    public function restore(Request $request, int $task, TaskService $tasks): JsonResponse
    {
        return $this->result($request, $tasks->trash($request->user(), $task, true), 'Task restored.');
    }
}
