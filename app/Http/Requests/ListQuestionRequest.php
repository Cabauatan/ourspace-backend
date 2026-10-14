<?php

namespace App\Http\Requests;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;

class ListQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Device;
    }

    public function rules(): array
    {
        return ['month' => ['sometimes', 'date_format:Y-m'], 'page' => ['sometimes', 'integer', 'min:1'], 'couple_id' => ['prohibited']];
    }
}
