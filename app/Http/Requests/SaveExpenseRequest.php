<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('description'))) {
            $this->merge(['description' => trim($this->input('description'))]);
        }
    }

    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'string', 'regex:/\\A(?:0|[1-9][0-9]{0,7})(?:\\.[0-9]{1,2})?\\z/', 'not_in:0,0.0,0.00'],
            'category' => ['required', Rule::in(config('expenses.categories'))],
            'paid_by' => ['required', Rule::in(['partner_one', 'partner_two'])],
            'expense_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2100-12-31'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'couple_id' => ['prohibited'], 'partner_type' => ['prohibited'], 'currency' => ['prohibited'], 'created_at' => ['prohibited'], 'updated_at' => ['prohibited'], 'deleted_at' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['amount.regex' => 'Enter a PHP amount up to 99,999,999.99 with at most two decimal places.', 'amount.string' => 'Send the amount as decimal text.'];
    }
}
