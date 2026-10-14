<?php

namespace App\Services;

use App\Http\Resources\QuestionDayResource;
use App\Models\ActivityLog;
use App\Models\CoupleProfile;
use App\Models\DailyQuestion;
use App\Models\Device;
use App\Models\QuestionAnswer;
use App\Models\QuestionDay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuestionService
{
    public function today(): string
    {
        return now(config('tasks.timezone'))->toDateString();
    }

    private function lockActor(Device $actor): void
    {
        CoupleProfile::query()->whereKey($actor->couple_id)->lockForUpdate()->firstOrFail();
        abort_unless(Device::query()->whereKey($actor->id)->whereNull('revoked_at')->exists(), 401);
    }

    public function todayData(Request $request): array
    {
        return DB::transaction(function () use ($request): array {
            $actor = $request->user();
            $this->lockActor($actor);
            $today = $this->today();
            $day = QuestionDay::query()->where('couple_id', $actor->couple_id)->where('question_date', $today)->first();
            if (! $day) {
                $last = QuestionDay::query()->where('couple_id', $actor->couple_id)->where('question_date', '<', $today)->orderByDesc('question_date')->first();
                $active = DailyQuestion::query()->where('active', true);
                $question = (clone $active)->where('id', '>', $last?->question_id ?? 0)->orderBy('id')->first() ?? $active->orderBy('id')->first();
                if ($question) {
                    $day = new QuestionDay;
                    $day->couple_id = $actor->couple_id;
                    $day->question_id = $question->id;
                    $day->question = $question->question;
                    $day->category = $question->category;
                    $day->question_date = $today;
                    $day->save();
                }
            }

            return ['today' => $today, 'timezone' => config('tasks.timezone'), 'question' => $day ? (new QuestionDayResource($day->load('answers')))->resolve($request) : null];
        });
    }

    public function answer(Device $actor, array $input): QuestionDay
    {
        return DB::transaction(function () use ($actor, $input): QuestionDay {
            $this->lockActor($actor);
            $day = QuestionDay::query()->where('couple_id', $actor->couple_id)->findOrFail($input['question_day_id']);
            $today = $this->today();
            if ($input['answer_date'] !== $today || $day->question_date->toDateString() !== $today) {
                throw ValidationException::withMessages(['answer_date' => 'You can only answer today’s question. Refresh for the current shared date.']);
            }
            $existing = $day->answers()->where('partner_type', $actor->partner_type)->first();
            if ($existing) {
                abort_if($existing->answer !== $input['answer'], 409, 'Your answer is already saved. Each partner can answer once.');

                return $day->load('answers');
            }
            $entry = new QuestionAnswer(['answer' => $input['answer']]);
            $entry->couple_id = $actor->couple_id;
            $entry->question_id = $day->question_id;
            $entry->question_day_id = $day->id;
            $entry->partner_type = $actor->partner_type;
            $entry->answer_date = $today;
            $entry->save();
            ActivityLog::record($actor, 'question_answered');

            return $day->load('answers');
        });
    }
}
