<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Resources\JournalResource;
use App\Services\TransactionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Throwable;

class TransactionController extends Controller
{
    public function __construct(private readonly TransactionService $service) {}

    public function store(string $tenant, StoreTransactionRequest $request): JsonResponse
    {
        $journal = retry(
            times: 3,
            callback: fn () => $this->service->create($request->validated()),
            sleepMilliseconds: 50,
            when: fn (Throwable $e) => $e instanceof UniqueConstraintViolationException,
        );

        return (new JournalResource($journal->load('lines.account', 'tags')))
            ->response()
            ->setStatusCode(201);
    }
}
