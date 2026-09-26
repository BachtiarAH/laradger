<?php

namespace App\Services\Ai\Tools\Support;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Rebuilds a real FormRequest from a model-supplied payload so a tool can call
 * an existing controller method and inherit its validation, policies,
 * transactions, and audit logging.
 *
 * This is deliberately the *only* path a write tool may use. It means the
 * assistant can never reach behaviour the HTTP API does not already allow for
 * the confirming user.
 */
class FormRequestInvoker
{
    /**
     * @template TRequest of FormRequest
     *
     * @param  class-string<TRequest>  $formRequest
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $routeParameters  Resolved models, keyed as they appear in the URI (e.g. `journal`).
     * @param  array<string, mixed>  $server
     * @return TRequest
     */
    public function make(
        string $formRequest,
        array $payload,
        array $routeParameters = [],
        array $server = [],
    ): FormRequest {
        $uri = '/'.ltrim(implode('/', array_map(
            static fn (mixed $parameter): string => rawurlencode((string) $parameter),
            array_values($routeParameters),
        )), '/');

        $base = Request::create($uri, 'POST', $payload, [], [], $server + [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        // Resolved once, from the guard, and pinned onto both requests. A tool
        // must always act as the user who is confirming it — never as anybody
        // the payload or a route parameter could imply.
        $user = auth()->user();

        $base->setUserResolver(fn (?string $guard = null) => $user);

        $route = new Route(['POST'], $uri, []);

        // bind() must run before setParameter(): Route::parameters() throws
        // "Route is not bound" until the parameters array exists.
        $route->bind($base);

        foreach ($routeParameters as $key => $value) {
            $route->setParameter((string) $key, $value);
        }

        /** @var TRequest $request */
        $request = $formRequest::createFrom($base);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->setUserResolver(fn (?string $guard = null) => $user);
        $request->setRouteResolver(fn (): Route => $route);

        // Runs authorize() then rules(), throwing ValidationException /
        // AuthorizationException exactly as it would over HTTP.
        $request->validateResolved();

        return $request;
    }

    /**
     * Build the request with no route-bound models, e.g. a collection endpoint.
     *
     * @template TRequest of FormRequest
     *
     * @param  class-string<TRequest>  $formRequest
     * @param  array<string, mixed>  $payload
     * @return TRequest
     */
    public function makeFor(string $formRequest, array $payload, string $uri = '/'): FormRequest
    {
        return $this->make($formRequest, $payload, [], ['REQUEST_URI' => $uri]);
    }
}
