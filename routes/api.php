<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AiJournalDraftController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\JournalController;
use App\Http\Controllers\Api\JournalLineController;
use App\Http\Controllers\Api\JournalTagController;
use App\Http\Controllers\Api\NextReferenceController;
use App\Http\Controllers\Api\OverviewController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\TenantController;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

Route::get('/docs', function () {
    $path = base_path('docs/openapi.yaml');

    if (! file_exists($path)) {
        abort(404, 'API documentation not found.');
    }

    $yaml = file_get_contents($path);

    if (request()->wantsJson()) {
        return response()->json(Yaml::parse($yaml));
    }

    if (request()->has('download')) {
        return response($yaml, 200)
            ->header('Content-Type', 'application/yaml; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="openapi.yaml"');
    }

    return response($yaml, 200)->header('Content-Type', 'text/plain; charset=utf-8');
});

Route::prefix('v1')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/tenants', [TenantController::class, 'index']);
        Route::post('/tenants', [TenantController::class, 'store']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    Route::prefix('{tenant}')->middleware(['auth:sanctum', 'tenant'])->group(function () {
        Route::get('journals/next-reference', [NextReferenceController::class, 'journalReference']);
        Route::get('accounts/next-code', [NextReferenceController::class, 'accountCode']);
        Route::get('accounts/{account}/journal-lines', [AccountController::class, 'journalLines']);
        Route::get('accounts/{account}/analytics', [AccountController::class, 'analytics']);
        Route::apiResource('accounts', AccountController::class);
        Route::apiResource('journals', JournalController::class);
        Route::post('journals/ai-draft', [AiJournalDraftController::class, 'store']);
        Route::post('journals/{journal}/reverse', [JournalController::class, 'reverse']);
        Route::apiResource('journal-lines', JournalLineController::class);
        Route::apiResource('journal-tags', JournalTagController::class)->only(['index', 'store']);
        Route::apiResource('tags', TagController::class);
        Route::apiResource('budgets', BudgetController::class);
        Route::apiResource('audit-logs', AuditLogController::class)->only(['index', 'show']);
        Route::get('overview', [OverviewController::class, 'index']);
    });
});
