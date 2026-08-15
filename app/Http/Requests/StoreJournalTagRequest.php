<?php

namespace App\Http\Requests;

use App\Models\JournalTag;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJournalTagRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', JournalTag::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'journal_id' => ['required', 'uuid', Rule::exists('journals', 'id')->where('tenant_id', TenantContext::id())],
            'tag_id' => ['required', 'uuid', Rule::exists('tags', 'id')->where('tenant_id', TenantContext::id())],
        ];
    }
}
