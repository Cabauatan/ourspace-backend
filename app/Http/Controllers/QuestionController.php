<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListQuestionRequest;
use App\Http\Requests\SaveQuestionAnswerRequest;
use App\Http\Resources\QuestionDayResource;
use App\Models\QuestionDay;
use App\Services\QuestionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuestionController extends Controller
{
    public function today(Request $request, QuestionService $service): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'A little question for today.', 'data' => $service->todayData($request)]);
    }

    public function index(ListQuestionRequest $request, QuestionService $service): JsonResponse
    {
        $month = $request->validated('month') ?? substr($service->today(), 0, 7);
        $first = CarbonImmutable::createFromFormat('!Y-m', $month);
        $page = QuestionDay::query()->where('couple_id', $request->user()->couple_id)->whereBetween('question_date', [$first->toDateString(), $first->endOfMonth()->toDateString()])->with('answers')->orderByDesc('question_date')->orderByDesc('id')->paginate(10);

        return response()->json(['success' => true, 'message' => 'Your question history.', 'data' => ['month' => $month, 'items' => QuestionDayResource::collection($page->items())->resolve($request), 'pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]]);
    }

    public function store(SaveQuestionAnswerRequest $request, QuestionService $service): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Your answer is saved.', 'data' => (new QuestionDayResource($service->answer($request->user(), $request->validated())))->resolve($request)]);
    }
}
