<?php

namespace Subtain\LaravelPayments;

/**
 * Generates a non-reversible fingerprint for a gateway API key.
 *
 * The fingerprint exposes just enough of the key to identify WHICH key was
 * active at the time of a payment — without exposing the key itself. This is
 * critical for audit trails: when a key is rotated, you can look at any
 * historical payment record and know exactly which key version processed it.
 *
 * ## Format
 *
 * first4 + '****' + last4
 *
 * Examples:
 *   'sk_live_abcdef1234567890'  →  'sk_l****7890'
 *   'abc123'                    →  'abc1****3'  (overlap allowed for short keys)
 *   null / ''                   →  null
 *
 * ## Usage
 *
 * ```php
 * KeyFingerprint::of('sk_live_abcdef1234567890');
 * // → 'sk_l****7890'
 *
 * KeyFingerprint::forGateway('match2pay');
 * // → reads config('lp_payments.gateways.match2pay') and returns an array
 * //   of fingerprints for all configured key fields of that gateway.
 * ```
 */
class KeyFingerprint
{
    /**
     * Generate a fingerprint from a raw key string.
     *
     * Returns null when the key is null or empty so callers can safely skip
     * storing/logging the fingerprint without any conditional logic.
     *
     * @param  string|null  $key  The raw API key, secret, or token.
     * @return string|null        A safe fingerprint or null if no key provided.
     */
    public static function of(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        $len = mb_strlen($key);

        // Keys shorter than 4 chars: just mask the entire thing.
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        $first = mb_substr($key, 0, 4);
        $last  = mb_substr($key, -4);

        return $first . '****' . $last;
    }

    /**
     * Resolve fingerprints for all configured key fields of a gateway.
     *
     * Reads `config('lp_payments.gateways.{gateway}.key_fields')` to find
     * which config keys hold authentication credentials, then returns a map
     * of field → fingerprint for each one that has a non-empty value.
     *
     * If a gateway has no `key_fields` configured, falls back to the common
     * default fields: ['api_key', 'api_token', 'secret', 'postback_key'].
     *
     * @param  string  $gateway  Gateway name, e.g. "match2pay", "fanbasis".
     * @return array<string, string>  Map of field name → fingerprint string.
     */
    public static function forGateway(string $gateway): array
    {
        $gatewayConfig = config("lp_payments.gateways.{$gateway}", []);

        $keyFields = $gatewayConfig['key_fields']
            ?? ['api_key', 'api_token', 'secret', 'postback_key'];

        $fingerprints = [];

        foreach ($keyFields as $field) {
            $value       = $gatewayConfig[$field] ?? null;
            $fingerprint = static::of($value);

            if ($fingerprint !== null) {
                $fingerprints[$field] = $fingerprint;
            }
        }

        return $fingerprints;
    }

    /**
     * Resolve the single "primary" key fingerprint for a gateway.
     *
     * Returns the fingerprint of the first key field that has a non-null value.
     * This is the value stored on the Payment DB record for quick querying.
     *
     * @param  string  $gateway  Gateway name, e.g. "fanbasis".
     * @return string|null
     */
    public static function primaryForGateway(string $gateway): ?string
    {
        $fingerprints = static::forGateway($gateway);

        return $fingerprints !== [] ? array_values($fingerprints)[0] : null;
    }
}
