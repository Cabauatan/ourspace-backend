<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim($this->input('title'))]);
        }
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'], 'content' => ['required', 'string', 'max:20000'],
            'visibility' => ['required', Rule::in(['shared', 'private'])], 'pinned' => ['required', 'boolean'],
            'couple_id' => ['prohibited'], 'created_by_partner' => ['prohibited'], 'partner_type' => ['prohibited'], 'created_at' => ['prohibited'], 'updated_at' => ['prohibited'], 'deleted_at' => ['prohibited'],
        ];
    }
}
