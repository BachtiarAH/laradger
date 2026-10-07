<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Area Enforcement
    |--------------------------------------------------------------------------
    |
    | An issued API key carries a fixed set of abilities (App\Enums\ApiAbility)
    | and this middleware resolves which one a route needs. A key that lacks it
    | gets a 403 — never a silent pass, and never a partial result.
    |
    | An ability is `area:tier`, and the two axes are independent: tiers climb
    | within an area, never across areas. A `ledger:destructive` key cannot read
    | budgets, because budgets are not ledger data.
    |
    | Once the area is known the tier follows from the HTTP method:
    |
    |   - `DELETE`, and any path in `destructive_paths`, requires `destructive`.
    |   - `POST`, `PUT`, and `PATCH` require `write`.
    |   - Everything else requires `read`.
    |
    | `ledger` is the fallback and is intentionally not listed below. A new
    | endpoint should have to be considered rather than drift into the least
    | restrictive area by accident, and the ledger is the area where over-granting
    | costs the most.
    |
    */

    'areas' => [
        'platform' => [
            'api/v1/admin/*',
        ],

        'audit' => [
            'api/v1/{tenant}/audit-logs*',
        ],

        'library' => [
            'api/v1/{tenant}/tags*',
            'api/v1/{tenant}/journal-templates*',
        ],

        'planning' => [
            'api/v1/{tenant}/allocations*',
            'api/v1/{tenant}/goals*',
            'api/v1/{tenant}/budgets*',
            // Allocation data hanging off an account, rather than off the
            // allocation itself. Checked before the ledger fallback so a
            // planning-only key can read it — it exposes no account balances.
            'api/v1/{tenant}/accounts/{account}/allocations',
        ],
    ],

    /*
    | State transitions that are not undoable through the API, and so are
    | treated as destructive despite not being DELETE requests.
    |
    | Reversing a journal is an accounting correction: the original stays, but
    | the ledger's history now contains an entry whose only purpose is to undo a
    | prior one. Cancelling an allocation abandons money that was reserved for a
    | stated purpose, and there is no endpoint that puts a cancelled allocation
    | back into an active state.
    |
    | Completing an allocation is deliberately *not* here. It is the ordinary end
    | of a savings plan rather than a correction, and an automation that funds a
    | goal needs to be able to finish it without holding delete rights. Note this
    | means it is a `planning:write`, not a `ledger:write` — finishing a savings
    | goal does not touch the ledger.
    */
    'destructive_paths' => [
        'api/v1/{tenant}/journals/{journal}/reverse',
        'api/v1/{tenant}/allocations/{allocation}/cancel',
    ],

    /*
    |--------------------------------------------------------------------------
    | Key Lifetime
    |--------------------------------------------------------------------------
    |
    | Every issued key gets an expiry, because an unbounded credential for an
    | unattended client is indistinguishable from a leaked one. `default_days`
    | applies when the issuer is not given a lifetime; `max_days` is a hard
    | ceiling so a key cannot be minted that outlives the rotation policy it was
    | issued under.
    |
    */

    'default_days' => (int) env('API_KEY_DEFAULT_DAYS', 90),

    'max_days' => (int) env('API_KEY_MAX_DAYS', 365),

    /*
    |--------------------------------------------------------------------------
    | Key Names
    |--------------------------------------------------------------------------
    |
    | Issued keys are prefixed so they are distinguishable from the tokens
    | `POST /login` hands out. A login token is minted with Sanctum's `*`
    | wildcard and is valid until logout; a key is scoped, expiring, and
    | revocable on its own. Being able to tell them apart in a token list — and
    | in a leak — is the point of the prefix.
    |
    */

    'name_prefix' => 'apikey:',

];
