<?php

namespace App\Http\Requests;

use App\Services\Ai\Gateway\AiGateway;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAiSettingsRequest extends FormRequest
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
            'provider' => ['sometimes', 'required', Rule::in(AiGateway::providerNames())],
            'model' => ['sometimes', 'nullable', 'string', 'max:191'],
            // No minimum length: local OpenAI-compatible servers (Ollama,
            // LM Studio) accept any non-empty string as a key, and a real
            // provider key is rejected by the provider, not by us. A blank key
            // is a no-op rather than a clear; clearing is a DELETE.
            'api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            // The server fetches this URL, so the scheme is restricted to
            // http/https rather than left to the HTTP client.
            'base_uri' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^https?:\/\//i'],
            'endpoint' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:#^/[^:]*$#'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'base_uri.regex' => 'The base URL must start with http:// or https://.',
            'endpoint.regex' => 'The endpoint must be a path starting with / and must not contain a scheme or host.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'api_key' => 'API key',
            'base_uri' => 'base URL',
        ];
    }
}
