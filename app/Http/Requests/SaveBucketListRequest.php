<?php

namespace App\Http\Requests;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveBucketListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Device;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('category'))) {
            $this->merge(['category' => mb_strtolower(trim($this->input('category')))]);
        }
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:10000'],
            'category' => ['required', 'string', 'max:60'], 'priority' => ['required', Rule::in(['low', 'medium', 'high'])],
            'target_date' => ['nullable', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:10000'],
            'images' => ['sometimes', 'array', 'max:10'],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=8192,max_height=8192'],
            'remove_media_ids' => ['sometimes', 'array', 'max:10'], 'remove_media_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'couple_id' => ['prohibited'], 'created_by_partner' => ['prohibited'], 'status' => ['prohibited'], 'completed_at' => ['prohibited'],
        ];
    }
}
