# Payment Reporting

The package ships with a fully isolated analytics layer that gives you Stripe-style reporting from your own data. All reports read directly from the package's own tables (`lp_payments`, `lp_payment_logs`, `lp_discount_codes`, `lp_discount_code_usages`) and never touch your application code.

## Architecture

```
PaymentReports facade
    └── ReportingManager
            ├── RevenueReport
            ├── ConversionReport
            ├── GatewayReport
            ├── DiscountReport
            ├── CustomerReport
            ├── RefundReport
            ├── WebhookAuditReport
            └── PayableReport
```

**Isolation guarantee:** The entire `src/Reporting/` namespace is read-only. It never writes to any table, never imports gateway code, and is completely decoupled from payment processing. Removing or disabling it cannot break anything.

---

## Quick Start

```php
use Subtain\LaravelPayments\Facades\PaymentReports;
use Subtain\LaravelPayments\Reporting\ReportingFilters;

$filters = ReportingFilters::make()
    ->thisYear()
    ->excludeSandbox()
    ->groupBy('month');

PaymentReports::revenue($filters)->summary();
PaymentReports::conversion($filters)->conversionRate();
PaymentReports::gateway($filters)->overview();
PaymentReports::discount($filters)->topCodes();
PaymentReports::customer($filters)->lifetimeValue();
PaymentReports::refund($filters)->timeToRefund();
PaymentReports::webhookAudit($filters)->duplicateWebhooks();
PaymentReports::payable($filters)->byType();
```

---

## ReportingFilters — Fluent Filter Builder

All reports accept a `ReportingFilters` instance. Unused filters are silently ignored.

```php
$filters = ReportingFilters::make()
    // Date presets
    ->today()
    ->thisMonth()
    ->lastMonth()
    ->thisYear()
    ->lastDays(30)
    // Custom date range
    ->from('2025-01-01')
    ->to('2025-06-30')
    // Gateway / currency
    ->gateways(['fanbasis', 'match2pay'])
    ->gateway('fanbasis')
    ->currencies(['USD', 'EUR'])
    ->currency('USD')
    // Status filter
    ->statuses(['paid', 'refunded'])
    // Sandbox
    ->excludeSandbox()       // real payments only (default)
    ->includeSandbox()       // real + sandbox
    ->sandboxOnly()          // sandbox only
    // Grouping
    ->groupBy('day')         // day | week | month | quarter | year
    // Payable types
    ->payableTypes(['App\Models\Order'])
    // Per-user / per-customer
    ->forUser(42)
    ->forEmail('user@example.com')
    // Top-N limiting
    ->limit(10);
```

---

## 1. Revenue Reports

```php
$report = PaymentReports::revenue($filters);

// Totals + AOV
$report->summary();
// [
//   'total_revenue'  => 24500.00,
//   'gross_revenue'  => 25760.00,
//   'total_discount' => 1260.00,
//   'total_payments' => 98,
//   'aov'            => 250.00,
//   'sandbox_revenue'=> 0.00,
// ]

// Per-gateway breakdown with share %
$report->byGateway();

// Per-currency breakdown
$report->byCurrency();

// Trend over time (grouped by period)
$report->trend();
// [['period' => '2025-01', 'total_revenue' => 4200.00, 'payments' => 17, 'aov' => 247.06], ...]

// Gross (before discount) vs net (after discount) per period
$report->grossVsNet();

// Real vs sandbox split
$report->sandboxVsReal();

// AOV per gateway with min/max
$report->aovByGateway();
```

---

## 2. Conversion & Funnel Reports

```php
$report = PaymentReports::conversion($filters);

// Overall initiation-to-paid rate
$report->conversionRate();
// ['initiated' => 120, 'paid' => 98, 'conversion_rate' => 81.67, ...]

// All statuses with counts + %
$report->funnelByStatus();

// Failure rate per gateway
$report->failureRateByGateway();

// Cancellation rate trend over time
$report->cancellationRate();

// Customers who tried payment more than once
$report->repeatAttempts(minimumAttempts: 2);

// Time from checkout initiation to payment confirmation
$report->checkoutToPaidTime();
// ['avg_minutes' => 3.5, 'min_minutes' => 0.2, 'max_minutes' => 47.8, 'sample_size' => 98]

// Conversion rate trend per period
$report->conversionTrend();
```

---

## 3. Gateway Performance Reports

```php
$report = PaymentReports::gateway($filters);

// Full per-gateway overview (success/fail/cancel/revenue)
$report->overview();

// Each gateway's % of total revenue
$report->revenueContribution();

// Webhook reliability: checkout_initiated vs webhook_received
$report->webhookReliability();
// [['gateway' => 'fanbasis', 'checkouts_initiated' => 120, 'webhooks_received' => 98, 'webhook_rate' => 81.67]]

// Failed checkout API calls per gateway per period (from logs)
$report->failedCheckouts();

// API key fingerprint audit — which key processed which payments
$report->keyRotationAudit();
// [['gateway' => 'fanbasis', 'key_fingerprint' => 'sk_l****7890', 'payments' => 80, 'first_used' => ...]]
```

---

## 4. Discount / Coupon Reports

```php
$report = PaymentReports::discount($filters);

// Overall discount summary
$report->summary();
// ['total_discounted_payments' => 42, 'total_revenue_lost' => 1260.00, 'discount_rate_of_total' => 12.5]

// Top N most-used codes
$report->topCodes();    // respects ->limit()

// Revenue lost per period
$report->revenueLost();

// Per-code stats with gateway breakdown
$report->perCodePerformance();

// Codes expiring soon / recently expired / no-expiry
$report->expiryAnalysis(soonDays: 7);
// ['expiring_soon' => [...], 'recently_expired' => [...], 'no_expiry' => [...]]

// Discounted vs full-price payment comparison
$report->discountedVsFull();
```

---

## 5. Customer / User Reports

```php
$report = PaymentReports::customer($filters);

// Customers with 2+ successful payments
$report->repeatBuyers(minimumPayments: 2);

// Lifetime value per customer (total paid)
$report->lifetimeValue();

// First vs last payment date + tenure days
$report->retentionSignals();

// Most-used gateway per customer
$report->gatewayPreference();

// New (first payment in window) vs returning customers
$report->newVsReturning();
// ['new_customers' => 45, 'returning_customers' => 12, 'new_revenue' => 6750.00, ...]

// Top N customers by total spend
$report->topCustomers(n: 10);
```

---

## 6. Refund Reports

> **Requires migration:** `php artisan vendor:publish --tag=payments-refund-migration && php artisan migrate`
> 
> This adds the `refunded_at` column. `timeToRefund()` gracefully returns a note if the column is missing.

```php
$report = PaymentReports::refund($filters);

// Total refunded amount + refund rate
$report->summary();
// ['total_refunded' => 5, 'total_amount' => 1250.00, 'refund_rate' => 5.10]

// Refund rate per gateway
$report->rateByGateway();

// Refund trend per period
$report->trend();

// Time between paid_at and refunded_at
$report->timeToRefund();
// ['avg_hours' => 4.5, 'min_hours' => 0.1, 'max_hours' => 72.0, 'sample_size' => 5]
```

---

## 7. Webhook / Audit Reports

```php
$report = PaymentReports::webhookAudit($filters);

// Count of each event type per gateway
$report->eventFrequency();

// Same invoice_id + event logged more than once (idempotency check)
$report->duplicateWebhooks(minimumOccurrences: 2);

// Webhooks with no matching payment record
$report->unmatchedWebhooks();

// Real vs sandbox log volume
$report->sandboxVsReal();

// Webhook signature verification failures
$report->signatureFailures();

// Event frequency trend per period
$report->eventFrequencyTrend();
```

---

## 8. Payable-Type Reports

```php
$report = PaymentReports::payable($filters);

// Revenue grouped by payable_type (Order, Subscription, etc.)
$report->byType();

// Each type's % of total revenue
$report->typeShareOfRevenue();

// Payments per type per time period
$report->trend();

// Payments with no linked model
$report->unlinkedPayments();

// Per-gateway breakdown for a specific payable type
$report->byGatewayForType('App\Models\Order');
```

---

## DB Compatibility

All reports work with **MySQL/MariaDB**, **PostgreSQL**, and **SQLite**. Date truncation functions are resolved automatically based on `DB::getDriverName()`.

---

## No Extra Dependencies

The reporting layer uses only what Laravel already provides: `DB::table()`, `selectRaw()`, `groupByRaw()`. No additional packages required.
