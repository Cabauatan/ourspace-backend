<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListBucketListRequest;
use App\Http\Requests\SaveBucketListRequest;
use App\Http\Resources\BucketListResource;
use App\Models\BucketListItem;
use App\Services\BucketListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BucketListController extends Controller
{
    public function index(ListBucketListRequest $request): JsonResponse
    {
        $input = $request->validated();
        $base = BucketListItem::query()->where('couple_id', $request->user()->couple_id);
        if ($request->boolean('trash')) {
            $base->onlyTrashed();
        }
        $query = clone $base;
        foreach (['category', 'status', 'priority'] as $key) {
            if (! empty($input[$key])) {
                $query->where($key, $input[$key]);
            }
        }
        if (isset($input['q']) && trim($input['q']) !== '') {
            $pattern = '%'.strtr(trim($input['q']), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->where(fn ($match) => $match->whereRaw("title LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("description LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("notes LIKE ? ESCAPE '!'", [$pattern]));
        }
        $page = $query->with('media')->orderByRaw("CASE WHEN status = 'completed' THEN 1 ELSE 0 END")->orderByRaw('CASE WHEN target_date IS NULL THEN 1 ELSE 0 END')->orderBy('target_date')->orderByRaw("CASE priority WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")->orderByDesc('id')->paginate(12);
        $pending = (clone $base)->where('status', 'pending')->count();
        $completed = (clone $base)->where('status', 'completed')->count();

        return response()->json(['success' => true, 'message' => 'Your shared dreams.', 'data' => [
            'items' => BucketListResource::collection($page->items())->resolve($request),
            'pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
            'summary' => ['pending' => $pending, 'completed' => $completed, 'total' => $pending + $completed],
            'categories' => (clone $base)->select('category')->distinct()->orderBy('category')->pluck('category')->all(),
        ]]);
    }

    private function result(Request $request, BucketListItem $item, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => (new BucketListResource($item))->resolve($request)], $status);
    }

    public function show(Request $request, int $item): JsonResponse
    {
        return $this->result($request, BucketListItem::query()->with('media')->where('couple_id', $request->user()->couple_id)->findOrFail($item), 'Your shared goal.');
    }

    public function store(SaveBucketListRequest $request, BucketListService $goals): JsonResponse
    {
        return $this->result($request, $goals->save($request->user(), $request->validated()), 'Goal created.', 201);
    }

    public function update(SaveBucketListRequest $request, int $item, BucketListService $goals): JsonResponse
    {
        return $this->result($request, $goals->save($request->user(), $request->validated(), $item), 'Goal updated.');
    }

    public function status(Request $request, int $item, BucketListService $goals): JsonResponse
    {
        $input = $request->validate(['status' => ['required', Rule::in(['pending', 'completed'])], 'couple_id' => ['prohibited'], 'created_by_partner' => ['prohibited'], 'completed_at' => ['prohibited']]);

        return $this->result($request, $goals->status($request->user(), $item, $input['status']), 'Goal status updated.');
    }

    public function destroy(Request $request, int $item, BucketListService $goals): JsonResponse
    {
        $goals->trash($request->user(), $item);

        return response()->json(['success' => true, 'message' => 'Goal moved to trash.', 'data' => null]);
    }

    public function restore(Request $request, int $item, BucketListService $goals): JsonResponse
    {
        return $this->result($request, $goals->trash($request->user(), $item, true), 'Goal restored.');
    }

    public function media(Request $request, int $item, int $media): BinaryFileResponse
    {
        $record = BucketListItem::query()->where('couple_id', $request->user()->couple_id)->findOrFail($item);
        $file = $record->media()->findOrFail($media);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($file->file_path), 404);

        return response()->file($disk->path($file->file_path),['Content-Type' => $file->mime_type, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
}
