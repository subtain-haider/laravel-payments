<?php

namespace Subtain\LaravelPayments;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Decides whether a given payment initiation should be sandboxed.
 *
 * A payment is sandboxed when ANY of these conditions is true:
 *
 *   1. sandbox.enabled = true  AND  the target gateway is in sandbox.gateways.
 *   2. The authenticated user's ID is in sandbox.bypass_user_ids.
 *   3. The authenticated user's role matches one of sandbox.bypass_roles.
 *
 * Conditions 2 and 3 apply on ANY environment — including production — so
 * internal QA accounts can always test without paying real money.
 *
 * This class is a pure resolver: it reads config and user data, makes a
 * decision, and returns it. No side effects.
 */
class SandboxResolver
{
    /**
     * Determine whether the given gateway + user combination should be sandboxed.
     *
     * @param  string                     $gateway  The gateway name being used (fanbasis, match2pay, etc.)
     * @param  Authenticatable|null       $user     The currently authenticated user, or null if unauthenticated.
     * @return bool                                 True if this payment should skip the real gateway.
     */
    public function shouldSandbox(string $gateway, ?Authenticatable $user = null): bool
    {
        if ($this->isEnvironmentSandboxed($gateway)) {
            return true;
        }

        if ($user !== null && $this->isUserBypassed($user)) {
            return true;
        }

        return false;
    }

    /**
     * Check whether the global sandbox switch covers this gateway.
     *
     * Returns true when sandbox.enabled = true AND either:
     *   - sandbox.gateways = '*' (all gateways), or
     *   - the gateway name appears in the CSV list.
     */
    private function isEnvironmentSandboxed(string $gateway): bool
    {
        if (! config('lp_payments.sandbox.enabled', false)) {
            return false;
        }

        $configured = config('lp_payments.sandbox.gateways', '*');

        if ($configured === '*') {
            return true;
        }

        $allowed = array_map('trim', explode(',', (string) $configured));

        return in_array($gateway, $allowed, true);
    }

    /**
     * Check whether the user is explicitly bypassed via ID or role.
     *
     * @param  Authenticatable  $user
     */
    private function isUserBypassed(Authenticatable $user): bool
    {
        $bypassIds   = config('lp_payments.sandbox.bypass_user_ids', []);
        $bypassRoles = config('lp_payments.sandbox.bypass_roles', []);

        if (! empty($bypassIds) && in_array($user->getAuthIdentifier(), $bypassIds, strict: false)) {
            return true;
        }

        if (! empty($bypassRoles) && $this->userHasRole($user, $bypassRoles)) {
            return true;
        }

        return false;
    }

    /**
     * Resolve a user's roles and check for intersection with the bypass list.
     *
     * Resolution order:
     *   1. sandbox.role_resolver callable (developer-provided override)
     *   2. $user->getRoleNames()              (spatie/laravel-permission — returns Collection of strings)
     *   3. $user->roles->pluck('name')        (hasMany Eloquent relationship)
     *   4. [$user->role]                      (plain string column)
     *
     * @param  Authenticatable  $user
     * @param  string[]         $bypassRoles
     */
    private function userHasRole(Authenticatable $user, array $bypassRoles): bool
    {
        $roles = $this->resolveUserRoles($user);

        foreach ($bypassRoles as $bypassRole) {
            if (in_array($bypassRole, $roles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the user's roles to a flat array of strings.
     *
     * @param  Authenticatable  $user
     * @return string[]
     */
    private function resolveUserRoles(Authenticatable $user): array
    {
        // 1. Developer-provided callable override
        $resolver = config('lp_payments.sandbox.role_resolver');

        if (is_callable($resolver)) {
            return (array) $resolver($user);
        }

        // 2. spatie/laravel-permission: getRoleNames() returns a Collection of role name strings
        if (method_exists($user, 'getRoleNames')) {
            return $user->getRoleNames()->toArray();
        }

        // 3. Eloquent hasMany: roles relationship returning models with a 'name' attribute
        if (isset($user->roles) && is_iterable($user->roles)) {
            $names = [];

            foreach ($user->roles as $role) {
                if (is_string($role)) {
                    $names[] = $role;
                } elseif (isset($role->name)) {
                    $names[] = $role->name;
                }
            }

            return $names;
        }

        // 4. Plain string column
        if (isset($user->role) && is_string($user->role)) {
            return [$user->role];
        }

        return [];
    }
}
