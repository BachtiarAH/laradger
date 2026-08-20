<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountController extends Controller
{
    public function index(string $tenant): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Account::class);

        $accounts = Account::with('parent')
            ->when(request('type'), fn ($query) => $query->where('type', request('type')))
            ->when(request('currency'), fn ($query) => $query->where('currency', request('currency')))
            ->when(request('status'), fn ($query) => $query->where('status', request('status')))
            ->when(request('search'), fn ($query) => $query->where('name', 'like', '%'.request('search').'%'))
            ->orderBy('code')
            ->paginate();

        return AccountResource::collection($accounts);
    }

    public function store(string $tenant, StoreAccountRequest $request): JsonResponse
    {
        $this->authorize('create', Account::class);

        $account = Account::create($request->validated());

        return (new AccountResource($account->load('parent')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $tenant, Account $account): AccountResource
    {
        $this->authorize('view', $account);

        return new AccountResource($account->load(['parent', 'children']));
    }

    public function update(string $tenant, UpdateAccountRequest $request, Account $account): AccountResource
    {
        $this->authorize('update', $account);

        $account->update($request->validated());

        return new AccountResource($account->fresh('parent'));
    }

    public function destroy(string $tenant, Account $account): JsonResponse
    {
        $this->authorize('delete', $account);

        Account::destroy($account->getKey());

        return response()->json(null, 204);
    }
}
