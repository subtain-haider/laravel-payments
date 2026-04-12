<?php

namespace Subtain\LaravelPayments\Gateways\Fanbasis;

/**
 * Fanbasis Webhooks API.
 *
 * Manage webhook subscriptions: list, create, delete, and test.
 *
 * Available event types:
 * - payment.succeeded, payment.failed, payment.expired, payment.canceled
 * - product.purchased
 * - subscription.created, subscription.renewed, subscription.completed, subscription.canceled
 * - dispute.created, dispute.updated
 * - refund.created
 *
 * @see https://apidocs.fan — Webhooks
 */
class WebhooksService
{
    public function __construct(
        protected FanbasisClient $client,
    ) {}

    /**
     * List all webhook subscriptions.
     *
     * @return array<string, mixed>
     */
    public function list(): array
    {
        return $this->client->get('webhook-subscriptions');
    }

    /**
     * Create a webhook subscription.
     *
     * The response includes a secret_key (shown only once) for signature verification.
     *
     * @param  array{
     *     webhook_url: string,
     *     event_types: array<string>,
     * }  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        return $this->client->post('webhook-subscriptions', $data);
    }

    /**
     * Delete a webhook subscription.
     *
     * @return array<string, mixed>
     */
    public function delete(string $webhookSubscriptionId): array
    {
        return $this->client->delete("webhook-subscriptions/{$webhookSubscriptionId}");
    }

    /**
     * Test a webhook subscription (sends a simulated event).
     *
     * @param  array{event_type: string}  $data
     * @return array<string, mixed>
     */
    public function test(string $webhookSubscriptionId, array $data): array
    {
        return $this->client->post("webhook-subscriptions/{$webhookSubscriptionId}/test", $data);
    }

    /**
     * Verify an incoming webhook signature using HMAC-SHA256.
     *
     * @param  string  $rawBody   The raw request body (NOT re-serialized JSON)
     * @param  string  $signature The value from x-webhook-signature or x-fanbasis-signature header
     * @param  string  $secret    Your webhook secret key
     */
    public static function verifySignature(string $rawBody, string $signature, string $secret): bool
    {
        if (empty($signature) || empty($secret)) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * All supported Fanbasis webhook event types.
     *
     * @return array<string>
     */
    public static function eventTypes(): array
    {
        return [
            'payment.succeeded',
            'payment.failed',
            'payment.expired',
            'payment.canceled',
            'product.purchased',
            'subscription.created',
            'subscription.renewed',
            'subscription.completed',
            'subscription.canceled',
            'dispute.created',
            'dispute.updated',
            'refund.created',
        ];
    }
}
