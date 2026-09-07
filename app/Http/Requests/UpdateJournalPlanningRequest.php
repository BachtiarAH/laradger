<?php

namespace App\Http\Requests;

use App\Models\Journal;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateJournalPlanningRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('linkPlanning', $this->route('journal'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'allocation_id' => [
                'nullable',
                'uuid',
                Rule::exists('allocations', 'id')->where('tenant_id', TenantContext::id()),
            ],
            'goal_id' => [
                'nullable',
                'uuid',
                Rule::exists('goals', 'id')->where('tenant_id', TenantContext::id()),
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->filled('allocation_id')) {
                $journal = $this->route('journal');
                if (is_string($journal)) {
                    $journal = Journal::find($journal);
                }

                if ($journal && ! $journal->lines()->whereHas('account', fn ($q) => $q->where('type', 'expense'))->exists()) {
                    $validator->errors()->add(
                        'allocation_id',
                        'Alokasi hanya dapat ditautkan ke jurnal yang memiliki akun beban (expense).'
                    );
                }
            }
        });
    }
}
