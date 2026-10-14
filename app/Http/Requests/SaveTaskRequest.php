<?php

namespace App\Http\Requests;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Device;
    }

    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:10000'],
            'assigned_to' => ['required', Rule::in(['partner_one', 'partner_two', 'both'])],
            'priority' => ['required', Rule::in(['low', 'medium', 'high'])], 'due_date' => ['nullable', 'date_format:Y-m-d'],
            'couple_id' => ['prohibited'], 'created_by_partner' => ['prohibited'], 'status' => ['prohibited'], 'completed_at' => ['prohibited']];
    }
}
