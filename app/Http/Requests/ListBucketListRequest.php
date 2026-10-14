<?php

namespace App\Http\Requests;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListBucketListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Device;
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:150'], 'category' => ['nullable', 'string', 'max:60'], 'priority' => ['nullable', Rule::in(['low', 'medium', 'high'])], 'status' => ['nullable', Rule::in(['pending', 'completed'])], 'trash' => ['sometimes', 'boolean'], 'page' => ['sometimes', 'integer', 'min:1'], 'couple_id' => ['prohibited']];
    }
}
