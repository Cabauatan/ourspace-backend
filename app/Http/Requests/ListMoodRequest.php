<?php

namespace App\Http\Requests;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListMoodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Device;
    }

    public function rules(): array
    {
        return ['month' => ['sometimes', 'date_format:Y-m'], 'partner' => ['nullable', Rule::in(['partner_one', 'partner_two'])], 'page' => ['sometimes', 'integer', 'min:1'], 'couple_id' => ['prohibited']];
    }
}
