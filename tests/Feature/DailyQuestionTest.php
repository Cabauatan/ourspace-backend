<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CoupleProfile;
use App\Models\DailyQuestion;
use App\Models\QuestionAnswer;
use App\Models\QuestionDay;
use Database\Seeders\DailyQuestionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DailyQuestionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function token(string $partner = 'partner_one'): string
    {
        CoupleProfile::query()->first() ?? CoupleProfile::factory()->create(['id' => 1]);

        return $this->postJson('/api/device/register', ['code' => 'test-recovery-code', 'partner_type' => $partner, 'device_name' => 'Question test', 'device_identifier' => (string) Str::uuid()])->assertCreated()->json('data.token');
    }

    private function day(): array
    {
        return $this->getJson('/api/questions/today')->assertOk()->json('data.question');
    }

    private function payload(array $day, string $answer = 'Our quiet walks.'): array
    {
        return ['question_day_id' => $day['id'], 'answer_date' => $day['question_date'], 'answer' => $answer];
    }

    public function test_answers_are_hidden_in_every_response_until_both_partners_answer(): void
    {
        $one = $this->token();
        $two = $this->token('partner_two');
        DailyQuestion::factory()->create();
        $this->withToken($one);
        $day = $this->day();
        $this->postJson('/api/questions/answers', $this->payload($day, 'ONE_SECRET'))->assertOk()->assertJsonPath('data.revealed', false)->assertJsonPath('data.answers.0.answer', 'ONE_SECRET');
        $this->withToken($two);
        foreach (['/api/questions/today', '/api/questions', '/api/dashboard'] as $path) {
            $result = $this->getJson($path)->assertOk();
            $this->assertStringNotContainsString('ONE_SECRET', $result->getContent());
        }
        $this->getJson('/api/questions/today')->assertOk()->assertJsonPath('data.question.answered.partner_one', true)->assertJsonCount(0, 'data.question.answers');
        $this->postJson('/api/questions/answers', $this->payload($day, 'TWO_SECRET'))->assertOk()->assertJsonPath('data.revealed', true)->assertJsonCount(2, 'data.answers')->assertJsonPath('data.answers.0.answer', 'ONE_SECRET')->assertJsonPath('data.answers.1.answer', 'TWO_SECRET');
        $this->withToken($one)->getJson('/api/questions')->assertOk()->assertJsonPath('data.items.0.revealed', true)->assertJsonPath('data.items.0.answers.1.answer', 'TWO_SECRET');
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.daily_question.question.answers.1.answer', 'TWO_SECRET');
        $this->assertDatabaseCount('question_answers', 2);
    }

    public function test_saved_answers_are_final_and_identical_retries_do_not_duplicate_records_or_activity(): void
    {
        $this->withToken($this->token());
        DailyQuestion::factory()->create();
        $day = $this->day();
        $payload = $this->payload($day);
        $this->postJson('/api/questions/answers', $payload)->assertOk();
        $count = ActivityLog::count();
        $this->postJson('/api/questions/answers', $payload)->assertOk();
        $this->assertSame($count, ActivityLog::count());
        $this->postJson('/api/questions/answers', $this->payload($day, 'A different answer'))->assertConflict();
        $this->assertDatabaseCount('question_answers', 1);
        $this->assertDatabaseHas('question_answers', ['partner_type' => 'partner_one', 'answer' => 'Our quiet walks.', 'answer_date' => $day['question_date']]);
    }

    public function test_assignment_is_shared_and_persistent_even_when_the_question_bank_changes(): void
    {
        $one = $this->token();
        $two = $this->token('partner_two');
        $first = DailyQuestion::factory()->create(['question' => 'First prompt?']);
        $second = DailyQuestion::factory()->create(['question' => 'Second prompt?']);
        $this->withToken($one);
        $day = $this->day();
        $this->assertSame('First prompt?', $day['question']);
        $first->update(['question' => 'Edited prompt?', 'active' => false]);
        $this->withToken($two);
        $this->assertSame($day['id'], $this->day()['id']);
        $this->assertSame('First prompt?', $this->day()['question']);
        $this->assertDatabaseCount('question_days', 1);
        $this->postJson('/api/questions/answers', $this->payload($day))->assertOk();
        $this->travelTo(now()->addDay());
        $next = $this->day();
        $this->assertSame('Second prompt?', $next['question']);
        $this->assertNotSame($day['id'], $next['id']);
        $this->assertSame($second->id, QuestionDay::findOrFail($next['id'])->question_id);
        $first->update(['active' => true]);
        $this->travelTo(now()->addDay());
        $this->assertSame('Edited prompt?', $this->day()['question']);
        $this->assertSame('First prompt?', QuestionDay::findOrFail($day['id'])->question);
        $this->assertDatabaseCount('question_days', 3);
    }

    public function test_shared_midnight_rejects_stale_answers_and_keeps_incomplete_history_hidden(): void
    {
        config(['tasks.timezone' => 'Asia/Singapore']);
        $this->travelTo(now()->setDate(2027, 2, 28)->setTime(15, 59));
        $one = $this->token();
        $two = $this->token('partner_two');
        DailyQuestion::factory()->count(2)->create();
        $this->withToken($one);
        $day = $this->day();
        $this->assertSame('2027-02-28', $day['question_date']);
        $this->postJson('/api/questions/answers', $this->payload($day, 'PAST_SECRET'))->assertOk();
        $this->travelTo(now()->addMinutes(2));
        $this->withToken($two);
        $this->postJson('/api/questions/answers', $this->payload($day))->assertUnprocessable()->assertJsonValidationErrors('answer_date');
        $next = $this->day();
        $this->assertSame('2027-03-01', $next['question_date']);
        $future = $this->payload($next);
        $future['answer_date'] = '2027-03-02';
        $this->postJson('/api/questions/answers', $future)->assertUnprocessable()->assertJsonValidationErrors('answer_date');
        $history = $this->getJson('/api/questions?month=2027-02')->assertOk()->assertJsonCount(1, 'data.items')->assertJsonCount(0, 'data.items.0.answers');
        $this->assertStringNotContainsString('PAST_SECRET', $history->getContent());
        $this->assertDatabaseCount('question_answers', 1);
    }

    public function test_month_history_is_paginated_and_never_returns_hidden_answers_on_other_pages(): void
    {
        $this->withToken($this->token('partner_two'));
        $question = DailyQuestion::factory()->create();
        for ($day = 1; $day <= 12; $day++) {
            $item = QuestionDay::factory()->create(['couple_id' => 1, 'question_id' => $question->id, 'question_date' => sprintf('2027-02-%02d', $day)]);
            QuestionAnswer::factory()->create(['question_day_id' => $item->id, 'answer' => 'HIDDEN_'.$day]);
        }
        QuestionDay::factory()->create(['couple_id' => 1, 'question_id' => $question->id, 'question_date' => '2027-02-28']);
        QuestionDay::factory()->create(['couple_id' => 1, 'question_id' => $question->id, 'question_date' => '2027-03-01']);
        $first = $this->getJson('/api/questions?month=2027-02')->assertOk()->assertJsonCount(10, 'data.items')->assertJsonPath('data.pagination.total', 13)->assertJsonPath('data.items.0.question_date', '2027-02-28');
        $second = $this->getJson('/api/questions?month=2027-02&page=2')->assertOk()->assertJsonCount(3, 'data.items');
        foreach ([$first, $second] as $response) {
            $this->assertStringNotContainsString('HIDDEN_', $response->getContent());
            foreach ($response->json('data.items') as $item) {
                $this->assertEmpty($item['answers']);
            }
        }
        $this->assertEmpty(array_intersect(array_column($first->json('data.items'), 'id'), array_column($second->json('data.items'), 'id')));
    }

    public function test_invalid_or_forged_input_cannot_submit_an_answer_as_another_partner(): void
    {
        $this->withToken($this->token());
        DailyQuestion::factory()->create();
        $day = $this->day();
        $this->postJson('/api/questions/answers', [])->assertUnprocessable()->assertJsonValidationErrors(['question_day_id', 'answer_date', 'answer']);
        $invalid = array_merge($this->payload($day), ['answer' => str_repeat('x', 4001), 'answer_date' => '2027-02-30', 'partner_type' => 'partner_two', 'couple_id' => 99, 'question_id' => 99, 'id' => 99]);
        $this->postJson('/api/questions/answers', $invalid)->assertUnprocessable()->assertJsonValidationErrors(['answer', 'answer_date', 'partner_type', 'couple_id', 'question_id', 'id']);
        $this->postJson('/api/questions/answers', $this->payload($day, '   '))->assertUnprocessable()->assertJsonValidationErrors('answer');
        $this->getJson('/api/questions?month=2027-13&page=0&couple_id=99')->assertUnprocessable()->assertJsonValidationErrors(['month', 'page', 'couple_id']);
        $this->assertDatabaseCount('question_answers', 0);
    }

    public function test_foreign_couple_day_ids_cannot_be_answered_and_foreign_answers_never_leak(): void
    {
        $this->withToken($this->token());
        $foreign = QuestionDay::factory()->create();
        QuestionAnswer::factory()->create(['question_day_id' => $foreign->id, 'answer' => 'FOREIGN_SECRET']);
        $this->getJson('/api/questions')->assertOk()->assertJsonCount(0, 'data.items');
        $this->postJson('/api/questions/answers', ['question_day_id' => $foreign->id, 'answer_date' => $foreign->question_date->toDateString(), 'answer' => 'Must not save'])->assertNotFound();
        foreach (['/api/questions/today', '/api/dashboard', '/api/questions'] as $path) {
            $response = $this->getJson($path)->assertOk();
            $this->assertStringNotContainsString('FOREIGN_SECRET', $response->getContent());
        }$this->assertDatabaseCount('question_answers', 1);
    }

    public function test_replacement_preserves_answers_and_old_or_missing_credentials_are_rejected(): void
    {
        $this->getJson('/api/questions')->assertUnauthorized();
        $this->getJson('/api/questions/today')->assertUnauthorized();
        $this->postJson('/api/questions/answers', [])->assertUnauthorized();
        $one = $this->token();
        $two = $this->token('partner_two');
        DailyQuestion::factory()->create();
        $this->withToken($one);
        $day = $this->day();
        $this->postJson('/api/questions/answers', $this->payload($day, 'KEPT_ANSWER'))->assertOk();
        $replacement = $this->postJson('/api/device/replace', ['code' => 'test-recovery-code', 'partner_type' => 'partner_one', 'device_name' => 'Replacement', 'device_identifier' => (string) Str::uuid()])->assertCreated()->json('data.token');
        $this->getJson('/api/questions')->assertUnauthorized();
        $this->getJson('/api/questions/today')->assertUnauthorized();
        $this->postJson('/api/questions/answers', $this->payload($day))->assertUnauthorized();
        $this->withToken($replacement)->getJson('/api/questions/today')->assertOk()->assertJsonPath('data.question.answers.0.answer', 'KEPT_ANSWER');
        $this->withToken($two)->postJson('/api/questions/answers', $this->payload($day, 'SECOND_ANSWER'))->assertOk()->assertJsonPath('data.revealed', true)->assertJsonPath('data.answers.0.answer', 'KEPT_ANSWER');
    }

    public function test_empty_catalog_and_idempotent_seeding_do_not_initialize_private_couple_data(): void
    {
        $this->withToken($this->token());
        $this->getJson('/api/questions/today')->assertOk()->assertJsonPath('data.question', null);
        $this->assertDatabaseCount('question_days', 0);
        $this->seed(DailyQuestionSeeder::class);
        $this->assertDatabaseCount('daily_questions', 30);
        $first = DailyQuestion::query()->first();
        $first->update(['active' => false]);
        $this->seed(DailyQuestionSeeder::class);
        $this->assertDatabaseCount('daily_questions', 30);
        $this->assertFalse($first->fresh()->active);
        $this->assertDatabaseCount('couple_profiles', 1);
        $day = $this->day();
        $this->assertNotSame($first->question, $day['question']);
    }
}
