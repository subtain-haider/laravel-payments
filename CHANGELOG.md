# Changelog

## v3.0.0 — Fanbasis Full API Integration

### Added
- **3 checkout modes**: Dynamic (API), Embedded (iframe), Static (pre-built link)
- **Subscription checkout**: `frequency_days`, `free_trial_days`, `auto_expire_after_x_periods`
- **Discount codes on checkout**: `discount_code` (pre-applied) and `allow_discount_codes` (customer input)
- **Full Fanbasis API services**: `checkoutSessions()`, `customers()`, `subscribers()`, `discountCodes()`, `products()`, `transactions()`, `refunds()`, `webhooks()`
- **FanbasisClient**: Centralized HTTP client with retry logic (429/5xx), configurable timeout
- **Webhook signature verification**: HMAC-SHA256 via `x-webhook-signature` header
- **Webhook management API**: Create, list, delete, and test webhook subscriptions
- **Customers API**: List, saved payment methods, direct charge
- **Subscribers API**: List (by product/session/status), cancel, extend, refund
- **Discount Codes API**: Full CRUD (percentage/fixed, duration, expiry, redemption limits)
- **Products API**: List all products with payment links
- **Transactions API**: Look up single or list all with filters
- **Refunds API**: Full and partial refunds with optional reason
- New config keys: `webhook_secret`, `creator_handle`, `timeout`, `retries`
- `FanbasisClient` registered as singleton in container for direct DI

### Changed
- `FanbasisGateway` rewritten from scratch — clean, generic, no project-specific patterns
- `parseWebhook()` uses standard `metadata.invoice_id` (no legacy key fallbacks)
- `staticCheckout()` uses generic `query_params` or `metadata` (no hardcoded field names)
- README rewritten — Stripe-quality documentation

All notable changes to `laravel-payments` will be documented in this file.

## 2.0.0 — 2026-04-12

### Added
- **`Payment` model** — Eloquent model with polymorphic `payable` relationship, tracks every payment attempt
- **`PaymentLog` model** — audit trail for every webhook received, status change, and checkout initiation
- **`PaymentService`** — high-level orchestration: `initiate()` creates DB record → calls gateway → updates record
- **`HasPayments` trait** — add to any model to get `$model->payments()`, `$model->hasPaidPayment()`, etc.
- **Status machine** — `Payment::transitionTo()` with guard rails preventing invalid transitions (e.g. paid → pending)
- **`PROCESSING` status** — new status for after checkout initiation, before gateway confirmation
- **Database migrations** — publishable via `php artisan vendor:publish --tag=payments-migrations`
- **Configurable table names** — defaults to `lp_payments` and `lp_payment_logs` (prefixed to avoid conflicts)
- **Auto webhook processing** — `WebhookController` now finds payment record, updates status, logs payload automatically
- **Idempotent webhooks** — duplicate webhooks for same status are silently skipped
- **Invalid transition logging** — logged to `lp_payment_logs` instead of throwing errors during webhook processing
- Events now include optional `$payment` model: `$event->payment` (null if not using DB tracking)

### Changed
- `illuminate/database` added as a required dependency
- `WebhookController` now writes to `lp_payment_logs` and updates `lp_payments` table automatically
- All three events (`PaymentSucceeded`, `PaymentFailed`, `WebhookReceived`) accept optional `Payment` model as second argument

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
