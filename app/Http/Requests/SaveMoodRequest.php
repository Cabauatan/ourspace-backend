<?php

namespace App\Http\Requests;

use App\Models\Device;
use App\Models\Mood;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveMoodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Device;
    }

    public function rules(): array
    {
        return ['mood' => ['required', Rule::in(Mood::OPTIONS)], 'note' => ['nullable', 'string', 'max:2000'], 'mood_date' => ['required', 'date_format:Y-m-d'], 'couple_id' => ['prohibited'], 'partner_type' => ['prohibited'], 'id' => ['prohibited']];
    }
}
