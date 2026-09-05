<?php

namespace App\Http\Requests;

use App\Models\Goal;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreGoalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Goal::class);
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
            'target_amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'target_date' => ['nullable', 'date'],
            'recurring_contribution_amount' => ['nullable', 'numeric', 'decimal:0,2', 'gte:0'],
            'contribution_frequency' => ['sometimes', 'string', 'in:weekly,monthly,yearly'],
            'status' => ['sometimes', 'string', 'in:active,achieved,paused,cancelled'],
        ];
    }
}
