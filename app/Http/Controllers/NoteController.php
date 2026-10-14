<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveNoteRequest;
use App\Http\Resources\NoteResource;
use App\Models\Note;
use App\Services\NoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NoteController extends Controller
{
    private function result(Request $request, Note $note, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => (new NoteResource($note))->resolve($request)], $status);
    }

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['visibility' => ['nullable', Rule::in(['shared', 'private'])], 'pinned' => ['nullable', 'boolean'], 'trash' => ['nullable', 'boolean'], 'q' => ['nullable', 'string', 'max:200'], 'page' => ['nullable', 'integer', 'min:1']]);
        $base = Note::query()->visibleTo($request->user());
        if ($request->boolean('trash')) {
            $base->onlyTrashed();
        }
        $query = clone $base;
        if (! empty($input['visibility'])) {
            $query->where('visibility', $input['visibility'] === 'shared' ? 'shared' : $request->user()->partner_type.'_private');
        }
        if (isset($input['pinned'])) {
            $query->where('pinned', $request->boolean('pinned'));
        }
        if (isset($input['q']) && trim($input['q']) !== '') {
            $pattern = '%'.strtr(trim($input['q']), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->where(fn ($match) => $match->whereRaw("title LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("content LIKE ? ESCAPE '!'", [$pattern]));
        }
        $page = $query->orderByDesc('pinned')->orderByDesc('updated_at')->orderByDesc('id')->paginate(20);

        return response()->json(['success' => true, 'message' => 'Your notes.', 'data' => [
            'items' => NoteResource::collection($page->items())->resolve($request),
            'pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'summary' => ['shared' => (clone $base)->where('visibility', 'shared')->count(), 'private' => (clone $base)->where('visibility', $request->user()->partner_type.'_private')->count(), 'pinned' => (clone $base)->where('pinned', true)->count()],
        ]]);
    }

    public function show(Request $request, int $note, NoteService $service): JsonResponse
    {
        return $this->result($request, $service->find($request->user(), $note), 'Your note.');
    }

    public function store(SaveNoteRequest $request, NoteService $service): JsonResponse
    {
        return $this->result($request, $service->save($request->user(), $request->validated()), 'Note created.', 201);
    }

    public function update(SaveNoteRequest $request, int $note, NoteService $service): JsonResponse
    {
        return $this->result($request, $service->save($request->user(), $request->validated(), $note), 'Note saved.');
    }

    public function pin(Request $request, int $note, NoteService $service): JsonResponse
    {
        $data = $request->validate(['pinned' => ['required', 'boolean'], 'couple_id' => ['prohibited'], 'created_by_partner' => ['prohibited'], 'visibility' => ['prohibited']]);

        return $this->result($request, $service->pin($request->user(), $note, (bool) $data['pinned']), 'Pin updated.');
    }

    public function destroy(Request $request, int $note, NoteService $service): JsonResponse
    {
        $service->trash($request->user(), $note);

        return response()->json(['success' => true, 'message' => 'Note moved to trash.', 'data' => null]);
    }

    public function restore(Request $request, int $note, NoteService $service): JsonResponse
    {
        return $this->result($request,$service->trash($request->user(),$note,true),'Note restored.');
    }
}
