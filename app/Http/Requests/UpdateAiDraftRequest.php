<?php

namespace App\Http\Requests;

use App\Models\AiActionDraft;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAiDraftRequest extends FormRequest
{
    /**
     * Ownership is checked here rather than in the controller so that it runs
     * *before* validation. Otherwise a member of the same tenant would get a
     * 422 for someone else's draft, which confirms it exists.
     *
     * A non-owner is answered with 404, not 403, so nothing is revealed.
     */
    public function authorize(): bool
    {
        $draft = $this->route('draft');

        abort_unless(
            $draft instanceof AiActionDraft
            && $draft->conversation?->user_id === $this->user()?->getKey(),
            404,
        );

        // Editing is only meaningful while the draft is still unsettled.
        abort_unless($draft->isPending(), 409, 'This draft has already been settled.');

        return true;
    }

    /**
     * Only the payload is editable, and only while pending. The tool, kind and
     * title are deliberately not editable: they describe *which* action this is,
     * and letting the client change them would let a review of "create journal"
     * turn into an execution of something else.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'payload' => ['required', 'array'],
        ];
    }
}
