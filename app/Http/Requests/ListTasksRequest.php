<?php

namespace App\Http\Requests;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Device;
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:150'], 'status' => ['nullable', Rule::in(['pending', 'in_progress', 'completed'])],
            'assigned_to' => ['nullable', Rule::in(['partner_one', 'partner_two', 'both'])], 'priority' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'overdue' => ['sometimes', 'boolean'], 'trash' => ['sometimes', 'boolean'], 'page' => ['sometimes', 'integer', 'min:1'], 'couple_id' => ['prohibited']];
    }
}
