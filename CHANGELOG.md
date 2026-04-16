# Changelog

## v4.0.0 — Config Key Renamed to Avoid Conflicts (Breaking)

### Breaking Changes
- **Config file renamed** from `config/payments.php` to `config/lp_payments.php`
- **Config key renamed** from `'payments'` to `'lp_payments'` — all `config('payments.xxx')` calls must be updated to `config('lp_payments.xxx')`

### Why
`payments` is an extremely common config key. Any Laravel project that already has its own `config/payments.php` (for Stripe, Cashier, or any in-house payment module) would silently have its config overwritten or conflict with this package's key. The `lp_` prefix (Laravel Payments) is already used for all database tables (`lp_payments`, `lp_payment_logs`, etc.) — this change makes the config consistent with that convention.

### Migration Guide

1. **Re-publish the config file:**
   ```bash
   php artisan vendor:publish --tag=payments-config --force
   ```
   This publishes the new `config/lp_payments.php`. Delete the old `config/payments.php` manually if it exists.

2. **Update any direct config references in your app:**
   ```php
   // Before
   config('payments.default')
   config('payments.gateways.fanbasis.api_key')

   // After
   config('lp_payments.default')
   config('lp_payments.gateways.fanbasis.api_key')
   ```

3. **No DB migrations needed** — table names are unchanged (`lp_payments`, `lp_payment_logs`, etc.)

### Other Changes
- All internal `config('payments.xxx')` calls across all gateways, clients, models, routes, and service provider updated to `config('lp_payments.xxx')`
- All documentation and docblock references updated

## v3.4.0 — Centralized Logging System

### Added
- **`PaymentLogger`** — Single central logging hub for the entire package. All log output from every gateway, HTTP client, webhook handler, and service now flows through this class instead of being scattered across individual files with raw `Log::` calls
- **`config('lp_payments.logging')`** — New config block with full control over:
  - `enabled` — global kill switch (`PAYMENTS_LOGGING_ENABLED`)
  - `level` — global minimum level, e.g. `'info'` in production (`PAYMENTS_LOG_LEVEL`)
  - `channels` — per-gateway channel routing + global `'default'`; supports any Laravel log channel (file, Slack, Telegram, ClickHouse, DB, stack, etc.)
  - `levels` — per-gateway minimum level overrides
  - `redact` — case-insensitive, recursive sensitive field masking (defaults cover `api_key`, `secret`, `signature`, `token`, `password`, and more)
- **`FanbasisClient` logging** — Previously completely silent. Now emits `debug` on every request/response and `error` on HTTP failures and gateway error bodies
- **`PremiumPayGateway` logging** — Previously completely silent. Now emits `info` on checkout initiation/success and `error` on HTTP/gateway failures and webhook parsing
- **`docs/logging.md`** — Full logging reference: all 15+ log events, every config option, 8 copy-paste recipes (dedicated file, per-gateway Slack, Telegram, ClickHouse, custom DB handler, stack, production setup, test silencing), redaction guide, channel resolution diagram, grep examples, and how to use `PaymentLogger` in custom gateways
- README: New **Logging** section with quick setup, message format, event reference table, and link to full docs

### Changed
- All `Log::` calls in `Match2PayClient`, `Match2PayGateway`, `RebornpayClient`, `RebornpayGateway` replaced with `PaymentLogger::` — same events, now routable and filterable
- Log events renamed from freeform sentences to dot-notation (`checkout.initiated`, `webhook.parsed`, `api.request`, etc.) for consistent machine-parseable format
- Log context for signature warnings now includes a `reason` key (e.g. `postback_key not configured`) for clearer diagnostics

### Non-breaking
- Zero configuration required — defaults to the app's existing log channel, identical behaviour to before
- `PaymentLog` DB audit trail (`lp_payment_logs`) is unaffected — it remains a separate structured record for webhooks

## v3.3.0 — Match2Pay Full Rewrite

### Added
- **`Match2PayClient`** — Dedicated HTTP client with `Content-Type: application/json`, retry logic (429/5xx), structured debug/error logging, sensitive field redaction
- **`SignatureService`** — Correct SHA-384 request signature algorithm: fixed key order, amount formatted to 8dp with trailing zeros stripped, customer serialized in Java toString() style (not JSON). Also verifies inbound callback signatures (DONE status only)
- **`DepositService`** — `POST /api/v2/payment/deposit` with full payload including customer object, signature auto-generated
- **`WithdrawalService`** — `POST /api/v2/payment/withdrawal` with TON memo support
- `Match2PayClient` registered as singleton in container
- `docs/gateways/match2pay.md` — full usage guide covering setup, checkout flows, 2-step selection, cryptocurrency reference, customer object, withdrawals, callback verification, wallet expiry

### Changed (breaking)
- **`Match2PayGateway` rewritten** — replaced the incorrect `ksort()` signature algorithm with the correct fixed-key-order SHA-384 algorithm matching nys-be production and official docs
- `parseWebhook()` now uses `finalAmount`/`finalCurrency` (account currency) instead of `transactionAmount`/`currency` (raw crypto) — this is the correct amount to credit
- `verifyWebhook()` now verifies the inbound callback signature (SHA-384 of amount+currency+status+token+secret) from the HTTP header, and only for `DONE` status (per docs)
- `checkout()` now accepts `extra['customer']` for a full customer object, or builds a minimal one from `CheckoutRequest` fields
- Config keys `endpoint` and `hash_algo` removed (no longer needed)
- Config key `base_url` default updated to `https://wallet.match2pay.com/api/v2/`
- README: Match2Pay entry updated with full docs link and improved example

## v3.2.0 — Rebornpay (UPI) Gateway

### Added
- **`RebornpayGateway`** — Full UPI/IMPS payment gateway for Indian payments
- **`RebornpayClient`** — Authenticated HTTP client with `X-API-Key` header, retry logic (429/5xx), structured debug/error logging
- **`PayinService`** — Creates pay-in transactions (`POST /api/v1/external/payin`)
- **`TransactionService`** — Status checks by transaction ID, client transaction ID, or UTR; UTR storage API
- **`SignatureService`** — Rebornpay's custom MD5 signature algorithm (Python `repr()` + alphabetical key sort + URL-encoding). Includes `verifyFromRawBody()` which detects float keys in raw JSON to prevent precision loss (e.g. `2000.0` vs `2000`) during signature computation
- `verifyWebhookSignature(string $rawBody)` method on `RebornpayGateway` — use instead of `verifyWebhook()` for accurate float handling
- `extra['amount_override']` — pass a pre-converted INR amount without affecting the USD amount stored in the payment record
- `extra['payment_option']` — switch between `'UPI'` (default) and `'IMPS'`
- `RebornpayClient` registered as singleton in container
- New config keys: `rebornpay.api_key`, `rebornpay.client_id`, `rebornpay.postback_key`, `rebornpay.base_url`, `rebornpay.timeout`, `rebornpay.retries`
- `docs/gateways/rebornpay.md` — full usage guide

## v3.1.0 — Built-in Discount Codes

### Added
- **`DiscountCode` model** — percentage or fixed discounts with: min order amount, max discount cap, total/per-user usage limits, start/expiry dates, active toggle, soft deletes
- **`DiscountCodeUsage` model** — polymorphic audit trail recording who used what code on which payable, with original/discount/final amounts
- **`DiscountService`** — `validate()`, `apply()`, `recordUsage()` for complete discount lifecycle
- **`DiscountResult` DTO** — immutable result of applying a discount (original, discount, final amounts)
- **Gateway scoping** — optional `gateways` JSON field to restrict codes to specific payment providers (null = all)
- **`ValidDiscountCode` validation rule** — drop-in Laravel validation rule for Form Requests
- **`DiscountType` enum** — `percentage`, `fixed`
- Two new publishable migration stubs: `create_discount_codes_table`, `create_discount_code_usages_table`
- Config keys: `tables.discount_codes`, `tables.discount_code_usages`
- Comprehensive README documentation with all fields, usage examples, validation flow, and extension guide

### Changed
- `PaymentServiceProvider` now registers `DiscountService` as singleton
- Migration publish includes discount tables alongside payment tables

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
