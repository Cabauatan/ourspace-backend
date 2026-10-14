<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CreateSpaceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'partner_one_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'partner_two_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'couple_id' => ['prohibited'],
            'setup_code_hash' => ['prohibited'],
            'recovery_code_hash' => ['prohibited'],
        ];
    }
}
