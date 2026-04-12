<?php

namespace Subtain\LaravelPayments\Contracts;

use Subtain\LaravelPayments\DTOs\CheckoutRequest;
use Subtain\LaravelPayments\DTOs\CheckoutResult;
use Subtain\LaravelPayments\DTOs\WebhookResult;

/**
 * Every payment gateway must implement this interface.
 *
 * To add a new gateway:
 * 1. Create a class that implements this interface
 * 2. Register it in config/payments.php under 'gateways'
 * 3. That's it — the package resolves it automatically
 *
 * @see \Subtain\LaravelPayments\DTOs\CheckoutRequest  What you send
 * @see \Subtain\LaravelPayments\DTOs\CheckoutResult   What you get back
 * @see \Subtain\LaravelPayments\DTOs\WebhookResult    What webhooks parse into
 */
interface PaymentGateway
{
    /**
     * Return the unique name of this gateway (e.g. 'fanbasis', 'stripe').
     */
    public function name(): string;

    /**
     * Create a checkout session and return a result containing the redirect URL.
     *
     * @throws \Subtain\LaravelPayments\Exceptions\PaymentException
     */
    public function checkout(CheckoutRequest $request): CheckoutResult;

    /**
     * Parse an incoming webhook request into a standardized result.
     *
     * This method should NOT trigger business logic — it only parses.
     * Business logic belongs in your app's event listeners.
     *
     * @param  array<string, mixed>  $payload  Raw webhook payload (typically $request->all())
     */
    public function parseWebhook(array $payload): WebhookResult;

    /**
     * Verify that a webhook request is authentic (signature check).
     *
     * Return true if the signature is valid, false otherwise.
     * If the gateway doesn't support signature verification, return true.
     *
     * @param  array<string, mixed>  $payload    Raw webhook payload
     * @param  array<string, mixed>  $headers    Request headers
     */
    public function verifyWebhook(array $payload, array $headers = []): bool;
}
