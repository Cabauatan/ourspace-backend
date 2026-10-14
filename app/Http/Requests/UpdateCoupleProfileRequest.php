<?php

namespace App\Http\Requests;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCoupleProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Device;
    }

    public function rules(): array
    {
        return [
            'partner_one_name' => ['sometimes', 'required', 'string', 'max:80'],
            'partner_two_name' => ['sometimes', 'required', 'string', 'max:80'],
            'relationship_start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'anniversary_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'couple_id' => ['prohibited'],
            'partner_type' => ['prohibited'],
            'setup_code_hash' => ['prohibited'],
            'recovery_code_hash' => ['prohibited'],
        ];
    }
}
