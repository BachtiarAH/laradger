<?php

namespace App\Http\Requests;

use App\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('tag'));
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $tag = $this->route('tag');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('tags', 'name')->where('tenant_id', TenantContext::id())->ignore($tag->id)],
            'type' => ['required', Rule::in(['priority', 'recurring', 'vendor', 'tax', 'transfer'])],
            'description' => ['nullable', 'string'],
        ];
    }
}
