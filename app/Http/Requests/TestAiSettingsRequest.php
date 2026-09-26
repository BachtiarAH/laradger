<?php

namespace App\Http\Requests;

use App\Services\Ai\Gateway\AiGateway;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TestAiSettingsRequest extends FormRequest
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
     * Everything here is optional: an empty body tests the stored settings, and
     * any supplied value is used for the call only, never persisted.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'provider' => ['sometimes', 'nullable', Rule::in(AiGateway::providerNames())],
            'model' => ['sometimes', 'nullable', 'string', 'max:191'],
            'api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'base_uri' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^https?:\/\//i'],
            'endpoint' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:#^/[^:]*$#'],
        ];
    }
}
