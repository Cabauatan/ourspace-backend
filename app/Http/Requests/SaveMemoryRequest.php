<?php

namespace App\Http\Requests;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveMemoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Device;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('tags'))) {
            $decoded = json_decode($this->input('tags'), true);
            if (is_array($decoded)) {
                $this->merge(['tags' => $decoded]);
            }
        }
        if (is_array($this->input('tags')) && collect($this->input('tags'))->every(fn ($tag) => is_string($tag))) {
            $this->merge(['tags' => array_values(array_unique(array_map(fn ($tag) => is_string($tag) ? mb_strtolower(trim($tag)) : $tag, $this->input('tags'))))]);
        }
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:10000'],
            'memory_date' => ['required', 'date_format:Y-m-d'],
            'location_name' => ['nullable', 'string', 'max:150'],
            'favorite' => ['sometimes', 'boolean'],
            'tags' => ['sometimes', 'array', 'max:10'],
            'tags.*' => ['required', 'string', 'max:40', 'distinct'],
            'images' => ['sometimes', 'array', 'max:10'],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=8192,max_height=8192'],
            'video' => ['sometimes', 'file', 'mimetypes:video/mp4,video/webm', 'max:20480'],
            'remove_media_ids' => ['sometimes', 'array', 'max:11'],
            'remove_media_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'couple_id' => ['prohibited'], 'created_by_partner' => ['prohibited'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_key_exists('latitude', $this->all()) !== array_key_exists('longitude', $this->all())) {
                $validator->errors()->add('latitude', 'Send both coordinates together, or clear both to remove the pin.');
            }
        }];
    }
}
