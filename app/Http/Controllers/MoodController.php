<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListMoodRequest;
use App\Http\Requests\SaveMoodRequest;
use App\Http\Resources\MoodResource;
use App\Models\Mood;
use App\Services\MoodService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MoodController extends Controller
{
    public function today(Request $request, MoodService $service): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Today’s shared check-ins.', 'data' => $service->todayData($request)]);
    }

    public function index(ListMoodRequest $request, MoodService $service): JsonResponse
    {
        $input = $request->validated();
        $month = $input['month'] ?? substr($service->today(), 0, 7);
        $first = CarbonImmutable::createFromFormat('!Y-m', $month);
        $query = Mood::query()->where('couple_id', $request->user()->couple_id)->whereBetween('mood_date', [$first->toDateString(), $first->endOfMonth()->toDateString()]);
        if (! empty($input['partner'])) {
            $query->where('partner_type', $input['partner']);
        }
        $counts = (clone $query)->select('partner_type', 'mood')->selectRaw('COUNT(*) AS total')->groupBy('partner_type', 'mood')->get();
        $statistics = [];
        foreach (['partner_one', 'partner_two'] as $partner) {
            $values = array_fill_keys(Mood::OPTIONS, 0);
            foreach ($counts->where('partner_type', $partner) as $row) {
                $values[$row->mood] = (int) $row->total;
            }
            $statistics[$partner] = ['total' => array_sum($values), 'counts' => $values];
        }
        $page = $query->orderByDesc('mood_date')->orderBy('partner_type')->orderByDesc('id')->paginate(20);

        return response()->json(['success' => true, 'message' => 'Your shared mood history.', 'data' => ['month' => $month, 'items' => MoodResource::collection($page->items())->resolve($request), 'statistics' => $statistics, 'pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]]);
    }

    public function store(SaveMoodRequest $request, MoodService $service): JsonResponse
    {
        $entry = $service->save($request->user(), $request->validated());

        return response()->json(['success' => true, 'message' => 'Your daily mood is saved.', 'data' => (new MoodResource($entry))->resolve($request)], $entry->wasRecentlyCreated ? 201 : 200);
    }
}
