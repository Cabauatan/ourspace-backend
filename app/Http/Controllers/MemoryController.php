<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListMemoriesRequest;
use App\Http\Requests\SaveMemoryRequest;
use App\Http\Resources\MemoryResource;
use App\Models\Memory;
use App\Models\MemoryTag;
use App\Services\MemoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MemoryController extends Controller
{
    public function map(Request $request): JsonResponse
    {
        $request->validate(['couple_id' => ['prohibited']]);
        $base = Memory::query()->where('couple_id', $request->user()->couple_id);
        $items = (clone $base)->whereNotNull('latitude')->whereNotNull('longitude')
            ->orderByDesc('memory_date')->orderByDesc('id')
            ->get(['id', 'title', 'memory_date', 'location_name', 'latitude', 'longitude', 'favorite'])
            ->map(fn (Memory $memory) => [
                'id' => $memory->id, 'title' => $memory->title,
                'memory_date' => $memory->memory_date->format('Y-m-d'),
                'location_name' => $memory->location_name, 'latitude' => $memory->latitude,
                'longitude' => $memory->longitude, 'favorite' => $memory->favorite,
            ]);

        return response()->json(['success' => true, 'message' => 'Your memory map.', 'data' => [
            'items' => $items, 'without_location' => (clone $base)->where(fn ($query) => $query->whereNull('latitude')->orWhereNull('longitude'))->count(),
        ]]);
    }

    public function index(ListMemoriesRequest $request): JsonResponse
    {
        $input = $request->validated();
        $base = Memory::query()->where('couple_id', $request->user()->couple_id);
        if ($request->boolean('trash')) {
            $base->onlyTrashed();
        }
        $query = clone $base;
        if (isset($input['q']) && trim($input['q']) !== '') {
            $pattern = '%'.strtr(trim($input['q']), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->where(function ($match) use ($pattern): void {
                $match->whereRaw("title LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("description LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("location_name LIKE ? ESCAPE '!'", [$pattern])->orWhereHas('tags', fn ($tags) => $tags->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
            });
        }
        if (! empty($input['year'])) {
            $query->whereYear('memory_date', (int) $input['year']);
        }
        if (isset($input['tag']) && trim($input['tag']) !== '') {
            $query->whereHas('tags', fn ($tags) => $tags->where('name', mb_strtolower(trim($input['tag']))));
        }
        if (isset($input['favorite'])) {
            $query->where('favorite', $request->boolean('favorite'));
        }
        $page = $query->with('media', 'tags')->orderByDesc('memory_date')->orderByDesc('id')->paginate(12);
        $years = (clone $base)->selectRaw('SUBSTR(memory_date,1,4) as year')->distinct()->orderByDesc('year')->pluck('year')->map(fn ($year) => (int) $year);
        $tags = MemoryTag::query()->whereIn('memory_id', (clone $base)->select('id'))->select('name')->distinct()->orderBy('name')->pluck('name');

        return response()->json(['success' => true, 'message' => 'Your shared memories.', 'data' => [
            'items' => MemoryResource::collection($page->items())->resolve($request),
            'pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
            'filters' => ['years' => $years, 'tags' => $tags, 'total_count' => (clone $base)->count(), 'favorite_count' => (clone $base)->where('favorite', true)->count()],
        ]]);
    }

    public function show(Request $request, int $memory): JsonResponse
    {
        $record = Memory::query()->where('couple_id', $request->user()->couple_id)->with('media', 'tags')->findOrFail($memory);

        return $this->result($request, $record, 'Your memory.');
    }

    public function store(SaveMemoryRequest $request, MemoryService $memories): JsonResponse
    {
        return $this->result($request, $memories->save($request->user(), $request->validated()), 'Memory saved.', 201);
    }

    public function update(SaveMemoryRequest $request, int $memory, MemoryService $memories): JsonResponse
    {
        return $this->result($request, $memories->save($request->user(), $request->validated(), $memory), 'Memory updated.');
    }

    public function favorite(Request $request, int $memory, MemoryService $memories): JsonResponse
    {
        $input = $request->validate(['favorite' => ['required', 'boolean'], 'couple_id' => ['prohibited'], 'created_by_partner' => ['prohibited']]);

        return $this->result($request, $memories->favorite($request->user(), $memory, (bool) $input['favorite']), 'Favorite updated.');
    }

    public function destroy(Request $request, int $memory, MemoryService $memories): JsonResponse
    {
        $memories->delete($request->user(), $memory);

        return response()->json(['success' => true, 'message' => 'Memory moved to trash.', 'data' => null]);
    }

    public function restore(Request $request, int $memory, MemoryService $memories): JsonResponse
    {
        return $this->result($request, $memories->restore($request->user(), $memory), 'Memory restored.');
    }

    public function media(Request $request, int $memory, int $media): BinaryFileResponse
    {
        $record = Memory::query()->where('couple_id', $request->user()->couple_id)->findOrFail($memory);
        $file = $record->media()->findOrFail($media);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($file->file_path), 404);

        return response()->file($disk->path($file->file_path), ['Content-Type' => $file->mime_type, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function result(Request $request, Memory $memory, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => (new MemoryResource($memory))->resolve($request)], $status);
    }
}
