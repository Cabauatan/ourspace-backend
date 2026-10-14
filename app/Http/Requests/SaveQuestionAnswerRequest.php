<?php

namespace App\Http\Requests;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;

class SaveQuestionAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Device;
    }

    public function rules(): array
    {
        return ['question_day_id' => ['required', 'integer', 'min:1'], 'answer_date' => ['required', 'date_format:Y-m-d'], 'answer' => ['required', 'string', 'max:4000'], 'partner_type' => ['prohibited'], 'couple_id' => ['prohibited'], 'question_id' => ['prohibited'], 'id' => ['prohibited']];
    }
}
