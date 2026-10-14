<?php

namespace App\Http\Requests;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;

class ListMemoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Device;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:150'],
            'year' => ['nullable', 'integer', 'between:1,9999'],
            'tag' => ['nullable', 'string', 'max:40'],
            'favorite' => ['nullable', 'boolean'],
            'trash' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'couple_id' => ['prohibited'],
        ];
    }
}
