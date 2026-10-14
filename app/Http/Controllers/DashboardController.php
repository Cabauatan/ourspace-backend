<?php

namespace App\Http\Controllers;

use App\Http\Resources\CoupleProfileResource;
use App\Http\Resources\MemoryResource;
use App\Models\ActivityLog;
use App\Models\Memory;
use App\Models\SharedTask;
use App\Services\CalendarService;
use App\Services\ChallengeService;
use App\Services\MoodService;
use App\Services\QuestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function __invoke(Request $request, CalendarService $calendar, MoodService $moods, QuestionService $questions, ChallengeService $challenges): JsonResponse
    {
        $couple = $request->attributes->get('couple');
        $latestMemory = Memory::query()->where('couple_id', $couple->id)->with('media', 'tags')->orderByDesc('memory_date')->orderByDesc('id')->first();
        $activities = ActivityLog::query()->where('couple_id', $couple->id)->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get()->map(fn (ActivityLog $activity): array => [
            'id' => $activity->id,
            'type' => $activity->type,
            'partner_type' => $activity->partner_type,
            'created_at' => $activity->created_at->toIso8601String(),
        ]);

        return response()->json(['success' => true, 'message' => 'Your shared dashboard.', 'data' => [
            'couple' => (new CoupleProfileResource($couple))->resolve($request),
            'calendar' => $calendar->upcoming($request),
            'moods' => $moods->todayData($request),
            'daily_question' => $questions->todayData($request),
            'love_progress' => Schema::hasTable('couple_challenges') && Schema::hasTable('achievements') && Schema::hasTable('couple_achievements') ? $challenges->progress($couple->id) : null,
            'recent_activity' => $activities,
            'latest_memory' => $latestMemory ? (new MemoryResource($latestMemory))->resolve($request) : null,
            'tasks' => ['pending' => SharedTask::query()->where('couple_id', $couple->id)->where('status', '!=', 'completed')->count(), 'overdue' => SharedTask::query()->where('couple_id', $couple->id)->where('status', '!=', 'completed')->where('due_date', '<', SharedTask::today())->count()],
        ]]);
    }
}
