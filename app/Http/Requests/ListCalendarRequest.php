<?php

namespace App\Http\Requests;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Device;
    }

    public function rules(): array
    {
        return ['month' => ['sometimes', 'date_format:Y-m'], 'event_type' => ['nullable', Rule::in(['date', 'anniversary', 'birthday', 'travel', 'reminder', 'personal', 'other'])], 'page' => ['sometimes', 'integer', 'min:1'], 'trash' => ['sometimes', 'boolean'], 'couple_id' => ['prohibited']];
    }
}
