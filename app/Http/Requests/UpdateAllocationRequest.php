<?php

namespace App\Http\Requests;

use App\Models\Allocation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAllocationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $allocation = $this->route('allocation');

        return $allocation instanceof Allocation
            && $this->user()->can('update', $allocation);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'target_amount' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gte:0'],
        ];
    }
}
