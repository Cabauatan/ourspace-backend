<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:100'],
            'partner_type' => ['required', Rule::in(['partner_one', 'partner_two'])],
            'device_name' => ['required', 'string', 'max:80'],
            'device_identifier' => ['required', 'uuid'],
            'partner_one_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'partner_two_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'couple_id' => ['prohibited'],
            'token_hash' => ['prohibited'],
            'is_primary' => ['prohibited'],
        ];
    }
}
