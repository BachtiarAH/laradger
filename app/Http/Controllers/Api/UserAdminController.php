<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdminUserRequest;
use App\Http\Requests\UpdateAdminUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class UserAdminController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->when(request('search'), function ($query): void {
                $search = '%'.request('search').'%';
                $query->where(function ($q) use ($search): void {
                    $q->where('name', 'like', $search)
                        ->orWhere('email', 'like', $search);
                });
            })
            ->when(request('status'), fn ($query) => $query->where('status', request('status')))
            ->when(
                request()->has('is_admin'),
                fn ($query) => $query->where('is_admin', filter_var(request('is_admin'), FILTER_VALIDATE_BOOL))
            )
            ->latest()
            ->paginate((int) (request('per_page', 15)));

        return UserResource::collection($users);
    }

    public function store(StoreAdminUserRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        return (new UserResource($user))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateAdminUserRequest $request, User $user): UserResource
    {
        $actor = $request->user();
        $data = $request->validated();

        // An empty password means "keep the current one".
        if (($data['password'] ?? null) === null) {
            unset($data['password']);
        }

        // Prevent the acting admin from locking themselves out of the panel.
        if ($user->is($actor) && array_key_exists('status', $data)) {
            throw ValidationException::withMessages([
                'status' => 'You cannot change your own account status.',
            ]);
        }

        $statusChanged = array_key_exists('status', $data) && $data['status'] !== $user->status->value;
        $user->update($data);

        // Blocking an account revokes every active session immediately.
        if ($statusChanged && $user->status->isBlocked()) {
            $user->tokens()->delete();
        }

        return new UserResource($user->fresh());
    }
}
