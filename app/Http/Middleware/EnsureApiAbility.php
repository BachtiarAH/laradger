<?php

namespace App\Http\Middleware;

use App\Enums\ApiAbility;
use App\Enums\ApiArea;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects a request whose API key lacks the ability the route needs.
 *
 * This is a second gate, not the first. `auth:sanctum` has already established
 * *who* is calling, and the route's policies and FormRequests still decide
 * *what* they may do — this only stops a credential that was issued for less
 * from reaching code that would otherwise accept it. A key holding
 * `planning:write` can fund a savings goal in a tenant its owner belongs to; it
 * cannot post a journal, read the audit log, or reach platform administration.
 *
 * The two axes are independent. Within an area the tiers climb, so a
 * `ledger:destructive` key writes and reads without holding those abilities. Across
 * areas they do not, so that same key cannot read budgets. An integration that
 * only reports on budgets gets exactly `planning:read` and nothing else — which
 * is the separation that makes a narrowly scoped key worth issuing at all.
 *
 * A token issued without abilities — every token `POST /login` hands out — is
 * Sanctum's `*` and passes here untouched. That is what makes adding this
 * middleware non-breaking: existing clients keep the access they already had.
 */
class EnsureApiAbility
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Nothing to check: this route is either public or reached before the
        // auth middleware ran. `auth:sanctum` answers 401 for the latter.
        if ($user === null) {
            return $next($request);
        }

        $required = $this->requiredFor($request);

        if ($required === null || $this->granted($user, $required)) {
            return $next($request);
        }

        abort(403, $this->denialMessage($required));
    }

    /**
     * The ability this route needs, or null when the route is not ability-gated.
     */
    public function requiredFor(Request $request): ?ApiAbility
    {
        $uri = $this->routeUri($request);

        $ability = ApiAbility::tryFrom(
            $this->areaFor($uri)->ability($this->tierFor($request, $uri)),
        );

        // An area with no tier for this verb — audit and platform have only
        // `read`. Falling back to that area's single tier is the tightest gate
        // that exists there: there is no narrower key to ask for, and no wider
        // one to accidentally hand out. Today no such route exists, but the
        // fallback means adding a POST under audit fails closed rather than
        // silently ungated.
        return $ability ?? ApiAbility::tryFrom($this->areaFor($uri)->ability('read'));
    }

    /**
     * Which part of the application a route belongs to.
     *
     * Checked in configured order and first match wins, so the narrower areas
     * must come before any broader one. `ledger` is the fallback for anything
     * unlisted.
     */
    private function areaFor(string $uri): ApiArea
    {
        foreach ((array) config('api-keys.areas', []) as $area => $patterns) {
            if ($this->matches($uri, (array) $patterns)) {
                return ApiArea::tryFrom((string) $area) ?? ApiArea::Ledger;
            }
        }

        return ApiArea::Ledger;
    }

    /**
     * How far inside its area the route goes: `destructive`, `write`, or `read`.
     */
    private function tierFor(Request $request, string $uri): string
    {
        if ($request->isMethod('DELETE') || $this->matches($uri, (array) config('api-keys.destructive_paths', []))) {
            return 'destructive';
        }

        // isMethod() takes exactly one argument and silently ignores the rest, so
        // listing the write methods there would quietly downgrade PUT and PATCH
        // to a read requirement.
        if (in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true)) {
            return 'write';
        }

        return 'read';
    }

    /**
     * The matched route's URI *template*, e.g.
     * `api/v1/{tenant}/journals/{journal}/reverse`.
     *
     * Not `$request->path()`, which holds the substituted values
     * (`api/v1/acme/journals/<uuid>/reverse`) and therefore never matches a
     * pattern written with `{tenant}`-style placeholders. Matching the template
     * is also what makes the configured patterns readable: they are the exact
     * strings `php artisan route:list` prints.
     */
    private function routeUri(Request $request): string
    {
        $route = $request->route();

        return is_object($route) && method_exists($route, 'uri')
            ? $route->uri()
            : $request->path();
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function matches(string $uri, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $uri)) {
                return true;
            }
        }

        return false;
    }

    private function granted(User $user, ApiAbility $required): bool
    {
        $abilities = $this->abilities($user);

        if (in_array('*', $abilities, true)) {
            return true;
        }

        return array_intersect($required->grantedBy(), $abilities) !== [];
    }

    /**
     * The abilities the presented credential actually carries.
     *
     * This reads the stored list rather than asking Sanctum's `tokenCan()`,
     * because the tiers here are cumulative and `can()` answers one exact name
     * at a time — which would mean asking it about `ledger:write` to discover
     * that a destructive key can also write.
     *
     * Two kinds of credential carry no list and are treated as unrestricted,
     * both deliberately:
     *
     * - A session or transient token. Abilities exist to constrain an unattended
     *   credential; an interactive browser session is a person, already gated by
     *   its cookie and CSRF token.
     * - A token row whose `abilities` column is null. That means it was minted
     *   before abilities existed, and every token `POST /login` still hands out
     *   is such a token. Reading null as "no abilities" would lock every existing
     *   client out of its own data the day this middleware shipped.
     *
     * @return array<int, string>
     */
    private function abilities(User $user): array
    {
        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return ['*'];
        }

        $abilities = $token->abilities ?? null;

        return is_array($abilities) ? $abilities : ['*'];
    }

    /**
     * Name the missing ability and what would satisfy it.
     *
     * A bare "forbidden" tells an unattended caller nothing it can act on, and
     * the caller is a machine that has no other way to find out. The tiers are
     * cumulative, so the message states the widest key that would work instead
     * of only the exact one.
     */
    private function denialMessage(ApiAbility $required): string
    {
        $names = $required->grantedBy();

        return sprintf(
            'This API key is missing the [%s] ability, which this endpoint requires. '
            .'Issue a key with [%s] to use it.',
            $required->value,
            implode('] or [', $names),
        );
    }
}
