<?php

namespace App\Http\Controllers;

use App\Events\ChatChanged;
use App\Http\Requests\CompleteChallengeRequest;
use App\Http\Resources\CoupleChallengeResource;
use App\Models\Challenge;
use App\Models\CoupleChallenge;
use App\Services\ChallengeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ChallengeController extends Controller
{
    private function result(mixed $data): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Your shared challenges.', 'data' => $data]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['category' => ['nullable', Rule::in(config('challenges.categories'))], 'difficulty' => ['nullable', Rule::in(config('challenges.difficulties'))], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = Challenge::query()->where('active', true)->with(['enrollments' => fn ($query) => $query->where('couple_id', $request->user()->couple_id)]);
        foreach (['category', 'difficulty'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        $page = $query->orderBy('id')->paginate(12);
        $items = $page->getCollection()->map(fn (Challenge $challenge) => ['id' => $challenge->id, 'title' => $challenge->title, 'description' => $challenge->description, 'category' => $challenge->category, 'difficulty' => $challenge->difficulty, 'points' => $challenge->points, 'enrollment' => $challenge->enrollments->first() ? (new CoupleChallengeResource($challenge->enrollments->first()))->resolve($request) : null])->all();

        return $this->result(['items' => $items, 'pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function mine(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['in_progress', 'completed', 'cancelled'])], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = CoupleChallenge::query()->where('couple_id', $request->user()->couple_id);
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        $page = $query->orderByDesc('id')->paginate(12);

        return $this->result(['items' => CoupleChallengeResource::collection($page->items())->resolve($request), 'pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function progress(Request $request, ChallengeService $service): JsonResponse
    {
        return $this->result($service->progress($request->user()->couple_id) + ['channel' => ChatChanged::channel($request->user())]);
    }

    public function start(Request $request, int $challenge, ChallengeService $service): JsonResponse
    {
        $request->validate(['points' => ['prohibited'], 'couple_id' => ['prohibited'], 'partner_type' => ['prohibited'], 'status' => ['prohibited']]);

        return $this->result((new CoupleChallengeResource($service->start($request->user(), $challenge)))->resolve($request));
    }

    public function status(Request $request, int $enrollment, ChallengeService $service): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['in_progress', 'cancelled'])], 'points' => ['prohibited'], 'couple_id' => ['prohibited'], 'completed_by_partner' => ['prohibited']]);

        return $this->result((new CoupleChallengeResource($service->status($request->user(), $enrollment, $data['status'])))->resolve($request));
    }

    public function complete(CompleteChallengeRequest $request, int $enrollment, ChallengeService $service): JsonResponse
    {
        return $this->result((new CoupleChallengeResource($service->complete($request->user(), $enrollment, $request->validated())))->resolve($request));
    }

    public function proof(Request $request, int $enrollment, int $media, ChallengeService $service): BinaryFileResponse
    {
        $item = $service->find($request->user(), $enrollment);
        abort_unless($media === $item->id && $item->proof_photo && Storage::disk('local')->exists($item->proof_photo), 404);

        return response()->file(Storage::disk('local')->path($item->proof_photo), ['Content-Type' => $item->proof_mime, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
}
