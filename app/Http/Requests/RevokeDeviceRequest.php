<?php

namespace App\Http\Requests;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;

class RevokeDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Device;
    }

    public function rules(): array
    {
        return ['device_id' => ['nullable', 'integer', 'min:1'], 'couple_id' => ['prohibited'], 'partner_type' => ['prohibited']];
    }
}
