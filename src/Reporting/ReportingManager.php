<?php

namespace Subtain\LaravelPayments\Reporting;

use Subtain\LaravelPayments\Reporting\Reports\ConversionReport;
use Subtain\LaravelPayments\Reporting\Reports\CustomerReport;
use Subtain\LaravelPayments\Reporting\Reports\DiscountReport;
use Subtain\LaravelPayments\Reporting\Reports\GatewayReport;
use Subtain\LaravelPayments\Reporting\Reports\PayableReport;
use Subtain\LaravelPayments\Reporting\Reports\RefundReport;
use Subtain\LaravelPayments\Reporting\Reports\RevenueReport;
use Subtain\LaravelPayments\Reporting\Reports\WebhookAuditReport;

/**
 * Central entry point for all payment reporting.
 *
 * Each method accepts a ReportingFilters instance and returns the
 * appropriate report object pre-configured with those filters.
 *
 * Registered as a singleton in the service container under 'payment.reports'.
 * Accessible via the PaymentReports facade.
 *
 * ## Usage
 *
 * ```php
 * use Subtain\LaravelPayments\Facades\PaymentReports;
 * use Subtain\LaravelPayments\Reporting\ReportingFilters;
 *
 * $filters = ReportingFilters::make()
 *     ->thisYear()
 *     ->excludeSandbox()
 *     ->groupBy('month');
 *
 * // Revenue
 * PaymentReports::revenue($filters)->summary();
 * PaymentReports::revenue($filters)->byGateway();
 * PaymentReports::revenue($filters)->trend();
 *
 * // Conversion
 * PaymentReports::conversion($filters)->conversionRate();
 * PaymentReports::conversion($filters)->failureRateByGateway();
 *
 * // Gateway performance
 * PaymentReports::gateway($filters)->overview();
 * PaymentReports::gateway($filters)->webhookReliability();
 *
 * // Discounts
 * PaymentReports::discount($filters)->topCodes();
 *
 * // Customers
 * PaymentReports::customer($filters)->lifetimeValue();
 *
 * // Refunds
 * PaymentReports::refund($filters)->timeToRefund();
 *
 * // Webhook audit
 * PaymentReports::webhookAudit($filters)->duplicateWebhooks();
 *
 * // Payable types
 * PaymentReports::payable($filters)->byType();
 * ```
 *
 * If no filters are provided, sensible defaults apply (no date restriction,
 * excludes sandbox, groups by month).
 */
class ReportingManager
{
    /**
     * Create a Revenue report.
     *
     * Covers: total revenue, gateway breakdown, currency breakdown,
     * time-period trends, gross vs net, sandbox vs real, AOV.
     */
    public function revenue(?ReportingFilters $filters = null): RevenueReport
    {
        return new RevenueReport($filters ?? ReportingFilters::make());
    }

    /**
     * Create a Conversion & Funnel report.
     *
     * Covers: initiation-to-paid rate, status funnel, failure rates,
     * cancellation trends, repeat attempts, checkout-to-paid latency.
     */
    public function conversion(?ReportingFilters $filters = null): ConversionReport
    {
        return new ConversionReport($filters ?? ReportingFilters::make());
    }

    /**
     * Create a Gateway Performance report.
     *
     * Covers: per-gateway overview, revenue contribution, webhook reliability,
     * failed checkouts (from logs), API key rotation audit.
     */
    public function gateway(?ReportingFilters $filters = null): GatewayReport
    {
        return new GatewayReport($filters ?? ReportingFilters::make());
    }

    /**
     * Create a Discount & Coupon report.
     *
     * Covers: summary, top codes, revenue lost, per-code performance,
     * expiry analysis, discounted vs full-price comparison.
     */
    public function discount(?ReportingFilters $filters = null): DiscountReport
    {
        return new DiscountReport($filters ?? ReportingFilters::make());
    }

    /**
     * Create a Customer report.
     *
     * Covers: repeat buyers, LTV, retention signals, gateway preference,
     * new vs returning customers, top customers.
     */
    public function customer(?ReportingFilters $filters = null): CustomerReport
    {
        return new CustomerReport($filters ?? ReportingFilters::make());
    }

    /**
     * Create a Refund report.
     *
     * Covers: total refunded, refund rate per gateway, refund trend,
     * time-to-refund (requires refunded_at migration).
     */
    public function refund(?ReportingFilters $filters = null): RefundReport
    {
        return new RefundReport($filters ?? ReportingFilters::make());
    }

    /**
     * Create a Webhook & Audit report.
     *
     * Covers: event frequency, duplicate webhook detection,
     * unmatched webhooks, sandbox vs real audit, signature failures.
     */
    public function webhookAudit(?ReportingFilters $filters = null): WebhookAuditReport
    {
        return new WebhookAuditReport($filters ?? ReportingFilters::make());
    }

    /**
     * Create a Payable-type report.
     *
     * Covers: revenue by payable_type (Order, Subscription, etc.),
     * revenue share, trends, unlinked payments, per-gateway breakdown for a type.
     */
    public function payable(?ReportingFilters $filters = null): PayableReport
    {
        return new PayableReport($filters ?? ReportingFilters::make());
    }
}
