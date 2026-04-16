<?php

namespace Subtain\LaravelPayments\Gateways\Rebornpay;

/**
 * Rebornpay webhook signature verification.
 *
 * Rebornpay signs webhooks using a custom algorithm that mimics Python's
 * url-encoding of its own repr() format. The algorithm:
 *
 * 1. Remove the "sign" field from the payload
 * 2. Inject "client_postback_key" into the data
 * 3. Sort all keys alphabetically (ksort)
 * 4. For each value, serialize using Python str() rules:
 *    - Strings: bare value (no quotes) — str("foo") → "foo"
 *    - Non-strings: Python repr() format — repr(True) → "True", repr([...]) → "[...]"
 * 5. URL-encode each key=value pair (PHP urlencode matches Python quote_plus)
 * 6. Join with "&" and compute MD5
 * 7. Compare with the received "sign" field
 *
 * PHP Quirk: urlencode() matches Python's urllib.parse.quote_plus() for ASCII
 * characters (spaces → "+", special chars → %XX), making it a correct match.
 *
 * IMPORTANT — float precision:
 * PHP JSON parses "2000.0" as integer 2000, losing the ".0" suffix.
 * To handle this correctly, pass the raw JSON body to verifyFromRawBody(),
 * which detects float keys in the raw string before JSON parsing.
 *
 * @see https://prod.api.rbpcloud.pro — Transaction Webhook docs
 */
class SignatureService
{
    /**
     * Verify a webhook signature from an already-parsed payload.
     *
     * NOTE: If the payload contains float fields (e.g. transaction_amount: 2000.0),
     * PHP's JSON parsing may drop the ".0" suffix and break signature verification.
     * Prefer verifyFromRawBody() when you have access to the raw request body.
     *
     * @param  array<string, mixed>  $payload  Full webhook payload (including "sign")
     * @param  string                $postbackKey  Your client_postback_key
     */
    public static function verify(array $payload, string $postbackKey): bool
    {
        $receivedSign = (string) ($payload['sign'] ?? '');

        if ($receivedSign === '' || $postbackKey === '') {
            return false;
        }

        $data = $payload;
        unset($data['sign']);
        $data['client_postback_key'] = $postbackKey;

        $expected = self::computeSignature($data);

        return hash_equals($expected, $receivedSign);
    }

    /**
     * Verify a webhook signature using the raw JSON body for accurate float detection.
     *
     * This is the preferred method. Rebornpay sends amounts as floats (e.g. 2000.0).
     * PHP's json_decode() converts "2000.0" to integer 2000, which changes the
     * repr() output and breaks the signature. By scanning the raw string first,
     * we can detect which numeric fields had decimal points and preserve ".0".
     *
     * @param  string  $rawBody     Raw JSON string from $request->getContent()
     * @param  string  $postbackKey Your client_postback_key
     */
    public static function verifyFromRawBody(string $rawBody, string $postbackKey): bool
    {
        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return false;
        }

        $receivedSign = (string) ($payload['sign'] ?? '');

        if ($receivedSign === '' || $postbackKey === '') {
            return false;
        }

        // Detect keys whose values had a decimal point in the raw JSON.
        // json_decode() strips ".0" from whole-number floats (2000.0 → 2000).
        // We need to reapply ".0" so repr() serializes them correctly.
        $floatKeys = self::detectFloatKeys($rawBody);

        $data = $payload;
        unset($data['sign']);
        $data['client_postback_key'] = $postbackKey;

        $expected = self::computeSignatureWithFloatContext($data, $floatKeys);

        return hash_equals($expected, $receivedSign);
    }

    /**
     * Compute the MD5 signature over the given data map.
     *
     * Sorts keys alphabetically, serializes values via Python str() rules,
     * URL-encodes each pair, joins with "&", then MD5 hashes the result.
     *
     * @param  array<string, mixed>  $data
     */
    public static function computeSignature(array $data): string
    {
        return self::computeSignatureWithFloatContext($data, []);
    }

    /**
     * Compute the MD5 signature with awareness of which keys hold float values.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string>         $floatKeys  Keys whose values should use float repr (appending ".0" if whole number)
     */
    protected static function computeSignatureWithFloatContext(array $data, array $floatKeys): string
    {
        ksort($data);

        $parts = [];

        foreach ($data as $key => $value) {
            $serialized = self::pythonStr($value, $floatKeys, (string) $key);
            $parts[]    = urlencode((string) $key) . '=' . urlencode($serialized);
        }

        return md5(implode('&', $parts));
    }

    /**
     * Serialize a value as Python's str() would — no quotes for strings,
     * repr() for everything else.
     *
     * Python's str(x):
     *   - str("hello")  → hello      (no quotes — just the raw value)
     *   - str(True)     → True        (repr)
     *   - str(2000.0)   → 2000.0     (repr)
     *   - str([1, 2])   → [1, 2]     (repr)
     *
     * @param  array<string>  $floatKeys  Keys that had ".0" in the raw JSON
     */
    protected static function pythonStr(mixed $value, array $floatKeys, string $key): string
    {
        if (is_string($value)) {
            return $value;
        }

        return self::pythonRepr($value, $floatKeys, $key);
    }

    /**
     * Serialize a value as Python's repr() would.
     *
     * Python's repr(x):
     *   - repr(None)        → None
     *   - repr(True)        → True
     *   - repr(False)       → False
     *   - repr(42)          → 42
     *   - repr(2000.0)      → 2000.0
     *   - repr("hello")     → 'hello'   (single quotes)
     *   - repr([1, 2])      → [1, 2]
     *   - repr({"a": 1})    → {'a': 1}
     *
     * @param  array<string>  $floatKeys  Keys that had ".0" in the raw JSON
     */
    protected static function pythonRepr(mixed $value, array $floatKeys, ?string $key): string
    {
        if (is_null($value)) {
            return 'None';
        }

        if (is_bool($value)) {
            return $value ? 'True' : 'False';
        }

        if (is_int($value)) {
            // If this key had a decimal point in the original JSON, append ".0"
            if ($key !== null && in_array($key, $floatKeys, true)) {
                return $value . '.0';
            }

            return (string) $value;
        }

        if (is_float($value)) {
            $str = (string) $value;
            // Ensure at least one decimal place (Python always shows "2000.0", never "2000")
            if (! str_contains($str, '.') && ! str_contains($str, 'E') && ! str_contains($str, 'e')) {
                $str .= '.0';
            }

            return $str;
        }

        if (is_string($value)) {
            return "'" . $value . "'";
        }

        if (is_array($value)) {
            // Sequential array → Python list
            if (array_values($value) === $value) {
                $items = array_map(
                    fn ($item) => self::pythonRepr($item, $floatKeys, null),
                    $value
                );

                return '[' . implode(', ', $items) . ']';
            }

            // Associative array → Python dict
            $items = [];
            foreach ($value as $k => $v) {
                $items[] = "'" . $k . "': " . self::pythonRepr($v, $floatKeys, (string) $k);
            }

            return '{' . implode(', ', $items) . '}';
        }

        return (string) $value;
    }

    /**
     * Scan the raw JSON string for numeric values that contain a decimal point.
     *
     * PHP's json_decode() strips ".0" from whole-number floats (2000.0 → 2000).
     * This method returns the set of keys whose values had ".0" in the raw string,
     * so we can restore the float representation during signature computation.
     *
     * @return array<string>  Keys whose values were floats in the raw JSON
     */
    protected static function detectFloatKeys(string $rawJson): array
    {
        $floatKeys = [];
        preg_match_all('/"(\w+)"\s*:\s*-?\d+\.\d+/', $rawJson, $matches);

        if (! empty($matches[1])) {
            $floatKeys = array_unique($matches[1]);
        }

        return $floatKeys;
    }
}
