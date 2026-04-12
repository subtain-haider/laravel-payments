# Changelog

All notable changes to `laravel-payments` will be documented in this file.

## 1.0.0 — 2024-04-12

### Added
- `PaymentGateway` interface — the contract every gateway implements
- `PaymentManager` — Laravel Manager pattern for resolving gateways
- `Payment` facade — clean static access: `Payment::gateway('fanbasis')->checkout(...)`
- **FanbasisGateway** — dynamic API checkout sessions + static payment links
- **PremiumPayGateway** — API-based checkout with bearer token auth
- **Match2PayGateway** — crypto payment gateway with HMAC signature verification
- `CheckoutRequest` DTO — gateway-agnostic input for checkout
- `CheckoutResult` DTO — standardized checkout response (redirect URL, transaction ID)
- `WebhookResult` DTO — standardized parsed webhook payload
- `PaymentStatus` enum — `pending`, `paid`, `failed`, `cancelled`, `refunded`
- `PaymentException` — thrown on gateway errors, includes raw response
- `WebhookController` — generic webhook receiver at `POST /payments/webhook/{gateway}`
- `PaymentSucceeded` event — dispatched on successful payment webhooks
- `PaymentFailed` event — dispatched on failed payment webhooks
- `WebhookReceived` event — dispatched for every webhook regardless of status
- Publishable config: `php artisan vendor:publish --tag=payments-config`
