<?php

use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function ($schedule) {
        $schedule->command('journal-templates:process')->dailyAt('06:00');
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            SetTenantContext::class,
        ]);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
        ]);

        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : route('login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (AccessDeniedHttpException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $exception->getMessage()], 403);
            }

            return response()->noContent(403);
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $exception->getMessage()], 401);
            }

            return response()->noContent(401);
        });

        $exceptions->render(function (ModelNotFoundException $exception, Request $request) {
            if ($request->is('api/*')) {
                $model = $exception->getModel();
                $name = match ($model) {
                    'App\\Models\\Account' => 'Account',
                    'App\\Models\\Budget' => 'Budget',
                    'App\\Models\\Journal' => 'Journal',
                    'App\\Models\\JournalTemplate' => 'Journal template',
                    'App\\Models\\AuditLog' => 'Audit log',
                    'App\\Models\\Tag' => 'Tag',
                    default => str(class_basename($model))->headline()->toString(),
                };

                return response()->json(['message' => $name.' not found.'], 404);
            }
        });
    })->create();
