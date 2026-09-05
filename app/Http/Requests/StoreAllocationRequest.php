<?php

namespace App\Http\Requests;

use App\Models\Allocation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAllocationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Allocation::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'target_amount' => ['nullable', 'numeric', 'decimal:0,2', 'gte:0'],
            'type' => ['sometimes', 'string', 'in:recurring,one_time'],
            'period_type' => ['sometimes', 'string', 'in:weekly,monthly,yearly,custom'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'roll_forward_mode' => ['sometimes', 'string', 'in:carry_over,release,reset'],
            'status' => ['sometimes', 'string', 'in:active,upcoming,fulfilled,skipped,completed,cancelled,expired'],
            'expires_at' => ['nullable', 'date'],
        ];
    }
}
