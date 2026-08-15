<?php

namespace App\Http\Requests;

use App\Models\Tag;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Tag::class);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('tags', 'name')->where('tenant_id', TenantContext::id())],
            'type' => ['required', Rule::in(['priority', 'recurring', 'vendor', 'tax', 'transfer'])],
            'description' => ['nullable', 'string'],
        ];
    }
}
