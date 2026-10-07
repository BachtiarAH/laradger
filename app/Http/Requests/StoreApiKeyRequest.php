<?php

namespace App\Http\Requests;

use App\Enums\ApiAbility;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApiKeyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Validated against the enum here rather than left to the issuer, so
            // an unknown ability comes back as a 422 naming the field the caller
            // actually sent instead of a runtime failure from the service layer.
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['required', 'string', 'distinct', Rule::in(ApiAbility::names())],
            'days' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('api-keys.max_days')],
            'label' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'abilities.required' => 'Choose at least one ability for this key.',
            'abilities.*.in' => 'That is not a known ability.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'abilities' => 'abilities',
            'days' => 'lifetime',
            'label' => 'label',
        ];
    }
}
