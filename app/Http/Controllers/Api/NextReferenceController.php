<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NextReferenceController extends Controller
{
    public function journalReference(string $tenant): JsonResponse
    {
        $this->authorize('create', Journal::class);

        return response()->json([
            'data' => [
                'reference' => Journal::nextReference(),
            ],
        ]);
    }

    public function accountCode(string $tenant, Request $request): JsonResponse
    {
        $this->authorize('create', Account::class);

        $validated = $request->validate([
            'type' => ['required', Rule::in(array_keys(Account::TYPE_CODE_PREFIXES))],
        ]);

        return response()->json([
            'data' => [
                'code' => Account::generateCode($validated['type']),
            ],
        ]);
    }
}
