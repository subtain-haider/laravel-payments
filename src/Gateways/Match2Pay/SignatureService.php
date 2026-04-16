<?php

namespace Subtain\LaravelPayments\Gateways\Match2Pay;

/**
 * Match2Pay signature service.
 *
 * Handles two distinct signature concerns:
 *
 * ── 1. Request signature (outbound — for deposit and withdrawal requests) ──
 *
 * Algorithm (per official docs):
 *   1. Build payload with all fields EXCEPT signature
 *   2. Concatenate values in this fixed key order:
 *      amount, apiToken, callbackUrl, currency, customer, failureUrl,
 *      paymentCurrency, paymentGatewayName, paymentMethod, successUrl, timestamp
 *      (skip any key not present in the payload)
 *   3. "amount" is formatted to 8 decimal places, trailing zeros stripped
 *      e.g. 10 → "10", 10.5 → "10.5", 10.50000000 → "10.5"
 *   4. "customer" is serialized in Java toString() style (NOT JSON):
 *      {firstName=X, lastName=Y, address={...}, contactInformation={...}, ...}
 *   5. Append the API secret to the end
 *   6. SHA-384 hash of the full string (lowercase hex)
 *
 * ── 2. Callback signature (inbound — for verifying webhook authenticity) ──
 *
 * Per docs: ONLY verify for status = "DONE".
 * Algorithm: sha384( transactionAmount(8dp) + transactionCurrency + status + apiToken + apiSecret )
 * The signature is delivered in the HTTP header (not the body).
 *
 * @see https://docs.match2pay.com — Signature and Callback signature sections
 */
class SignatureService
{
    /**
     * Fixed key order for request signature concatenation (per official docs).
     */
    protected const REQUEST_KEY_ORDER = [
        'amount',
        'apiToken',
        'callbackUrl',
        'currency',
        'customer',
        'failureUrl',
        'paymentCurrency',
        'paymentGatewayName',
        'paymentMethod',
        'successUrl',
        'timestamp',
    ];

    /**
     * Build the request signature for a deposit or withdrawal payload.
     *
     * @param  array<string, mixed>  $payload  Request body (without 'signature' key)
     * @param  string                $apiSecret
     */
    public static function buildRequestSignature(array $payload, string $apiSecret): string
    {
        unset($payload['signature']);

        $concatenated = '';

        foreach (self::REQUEST_KEY_ORDER as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            if ($key === 'amount') {
                $concatenated .= self::formatAmount($payload[$key]);
            } elseif ($key === 'customer') {
                $concatenated .= self::formatCustomer($payload[$key]);
            } else {
                $concatenated .= (string) $payload[$key];
            }
        }

        $concatenated .= $apiSecret;

        return hash('sha384', $concatenated);
    }

    /**
     * Verify an inbound callback signature.
     *
     * Per docs: only validate for status = "DONE".
     * The signature arrives in the HTTP header (e.g. X-Signature), not the body.
     *
     * @param  string  $transactionAmount  Raw value from callback body
     * @param  string  $transactionCurrency
     * @param  string  $status
     * @param  string  $apiToken
     * @param  string  $apiSecret
     * @param  string  $receivedSignature  From the request header
     */
    public static function verifyCallbackSignature(
        string $transactionAmount,
        string $transactionCurrency,
        string $status,
        string $apiToken,
        string $apiSecret,
        string $receivedSignature,
    ): bool {
        if ($receivedSignature === '') {
            return false;
        }

        $formattedAmount = self::formatAmount($transactionAmount);
        $expected        = hash('sha384', $formattedAmount . $transactionCurrency . $status . $apiToken . $apiSecret);

        return hash_equals($expected, strtolower($receivedSignature));
    }

    /**
     * Format a numeric amount to 8 decimal places with trailing zeros stripped.
     *
     * Examples (per docs):
     *   10        → "10"
     *   10.5      → "10.5"
     *   0.00011873 → "0.00011873"
     *   1.0        → "1"
     *
     * NOTE: When amount is 1 the docs show "1.00000000" before stripping,
     * resulting in "1". This matches the Python: f"{amount:f}".rstrip('0').rstrip('.')
     */
    public static function formatAmount(mixed $amount): string
    {
        $formatted = number_format((float) $amount, 8, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    /**
     * Format the customer object in Java toString() style for signature.
     *
     * Output format (order is fixed per docs):
     * {firstName=X, lastName=Y, address={address=A, city=C, country=CO, zipCode=Z, state=S},
     *  contactInformation={email=E, phoneNumber=P}, locale=L, dateOfBirth=D,
     *  tradingAccountLogin=TL, tradingAccountUuid=TU}
     *
     * @param  array<string, mixed>  $customer
     */
    public static function formatCustomer(array $customer): string
    {
        $address = $customer['address'] ?? [];
        $contact = $customer['contactInformation'] ?? [];

        $addressStr = '{address='  . ($address['address'] ?? '')
            . ', city='    . ($address['city'] ?? '')
            . ', country=' . ($address['country'] ?? '')
            . ', zipCode=' . ($address['zipCode'] ?? '')
            . ', state='   . ($address['state'] ?? '') . '}';

        $contactStr = '{email='       . ($contact['email'] ?? '')
            . ', phoneNumber=' . ($contact['phoneNumber'] ?? '') . '}';

        return '{firstName='             . ($customer['firstName'] ?? '')
            . ', lastName='              . ($customer['lastName'] ?? '')
            . ', address='               . $addressStr
            . ', contactInformation='    . $contactStr
            . ', locale='                . ($customer['locale'] ?? '')
            . ', dateOfBirth='           . ($customer['dateOfBirth'] ?? '')
            . ', tradingAccountLogin='   . ($customer['tradingAccountLogin'] ?? '')
            . ', tradingAccountUuid='    . ($customer['tradingAccountUuid'] ?? '') . '}';
    }
}
