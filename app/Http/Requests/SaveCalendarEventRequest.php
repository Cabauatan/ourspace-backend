<?php

namespace App\Http\Requests;

use App\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveCalendarEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Device;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:10000'],
            'event_type' => ['required', Rule::in(['date', 'anniversary', 'birthday', 'travel', 'reminder', 'personal', 'other'])],
            'start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'reminder_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'couple_id' => ['prohibited'], 'created_by_partner' => ['prohibited'], 'reminder_dismissed_at' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['reminder_at', 'end_date']) || ! $this->input('reminder_at')) {
                return;
            }
            $reminder = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $this->input('reminder_at'), config('tasks.timezone'));
            if ($reminder->format('Y-m-d\TH:i') !== $this->input('reminder_at')) {
                $validator->errors()->add('reminder_at', 'Choose a valid time in the shared timezone.');
            }
            if ($reminder->toDateString() > $this->input('end_date')) {
                $validator->errors()->add('reminder_at', 'The reminder must be on or before the event ends.');
            }
        }];
    }
}
