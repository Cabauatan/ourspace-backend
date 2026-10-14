<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class CompleteChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['proof_photo' => ['nullable', File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max(5120)->dimensions(Rule::dimensions()->maxWidth(8192)->maxHeight(8192))], 'points' => ['prohibited'], 'couple_id' => ['prohibited'], 'completed_by_partner' => ['prohibited'], 'completed_at' => ['prohibited'], 'status' => ['prohibited'], 'level' => ['prohibited']];
    }
}
