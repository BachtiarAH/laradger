<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateJournalLineRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('journal_line'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'journal_id' => ['sometimes', 'uuid', 'exists:journals,id'],
            'account_id' => ['sometimes', 'uuid', 'exists:accounts,id'],
            'debit' => ['required_without:credit', 'nullable', 'numeric', 'min:0'],
            'credit' => ['required_without:debit', 'nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
