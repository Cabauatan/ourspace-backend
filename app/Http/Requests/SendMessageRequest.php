<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $video = str_starts_with($this->file('attachment')?->getMimeType() ?? '', 'video/');

        return [
            'client_id' => ['required', 'uuid'], 'body' => ['nullable', 'string', 'max:4000', 'required_without:attachment'], 'reply_to_id' => ['nullable', 'integer', 'min:1'],
            'attachment' => ['nullable', $video ? File::types(['mp4', 'webm'])->max(20480) : File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max(5120)->dimensions(Rule::dimensions()->maxWidth(8192)->maxHeight(8192))],
            'partner_type' => ['prohibited'], 'couple_id' => ['prohibited'], 'conversation_id' => ['prohibited'], 'created_at' => ['prohibited'],
        ];
    }
}
