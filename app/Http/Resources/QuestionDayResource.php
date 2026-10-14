<?php

namespace App\Http\Resources;

use App\Models\QuestionAnswer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestionDayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $answers = $this->answers->where('couple_id', $request->user()->couple_id);
        $answered = ['partner_one' => $answers->contains('partner_type', 'partner_one'), 'partner_two' => $answers->contains('partner_type', 'partner_two')];
        $revealed = $answered['partner_one'] && $answered['partner_two'];
        $visible = $revealed ? $answers : $answers->where('partner_type', $request->user()->partner_type);

        return ['id' => $this->id, 'question' => $this->question, 'category' => $this->category, 'question_date' => $this->question_date->toDateString(), 'answered' => $answered, 'revealed' => $revealed, 'answers' => $visible->sortBy('partner_type')->map(fn (QuestionAnswer $answer): array => ['partner_type' => $answer->partner_type, 'answer' => $answer->answer, 'answer_date' => $answer->answer_date->toDateString()])->values()->all()];
    }
}
