<?php

namespace Subtain\LaravelPayments\Reporting\Reports;

use Illuminate\Support\Collection;
use Subtain\LaravelPayments\Enums\PaymentStatus;
use Subtain\LaravelPayments\Reporting\PaymentReport;

/**
 * Gateway performance and reliability analytics.
 *
 * Answers questions like:
 *   - Which gateway is the most reliable?
 *   - Which gateway drives the most revenue?
 *   - Are our webhooks being received reliably?
 *   - Which API key was used for a given payment?
 *
 * ## Usage
 *
 * ```php
 * $report = PaymentReports::gateway(
 *     ReportingFilters::make()->thisYear()->excludeSandbox()
 * );
 *
 * $report->overview();                // all gateways: success/fail/cancel + revenue
 * $report->revenueContribution();     // each gateway's % of total revenue
 * $report->webhookReliability();      // checkout_initiated vs webhook_received per gateway
 * $report->failedCheckouts();         // failed checkout attempts per gateway (from logs)
 * $report->keyRotationAudit();        // which key_fingerprint was used per payment, per gateway
 * ```
 */
class GatewayReport extends PaymentReport
{
    /**
     * Full performance overview per gateway.
     *
     * Returns a Collection ordered by total payments descending, one entry per gateway:
     *
     * ```php
     * [
     *   [
     *     'gateway'       => 'fanbasis',
     *     'total'         => 120,
     *     'paid'          => 98,
     *     'failed'        => 14,
     *     'cancelled'     => 8,
     *     'refunded'      => 2,
     *     'success_rate'  => 81.67,
     *     'failure_rate'  => 11.67,
     *     'total_revenue' => 24500.00,
     *   ],
     * ]
     * ```
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function overview(): Collection
    {
        $rows = $this->baseQuery()
            ->selectRaw('
                gateway,
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as paid,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as refunded,
                COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) as total_revenue
            ', [
                PaymentStatus::PAID->value,
                PaymentStatus::FAILED->value,
                PaymentStatus::CANCELLED->value,
                PaymentStatus::REFUNDED->value,
                PaymentStatus::PAID->value,
            ])
            ->groupBy('gateway')
            ->orderByDesc('total')
            ->get();

        return $rows->map(fn ($row) => [
            'gateway'       => $row->gateway,
            'total'         => (int) $row->total,
            'paid'          => (int) $row->paid,
            'failed'        => (int) $row->failed,
            'cancelled'     => (int) $row->cancelled,
            'refunded'      => (int) $row->refunded,
            'success_rate'  => $this->percentage($row->paid, $row->total),
            'failure_rate'  => $this->percentage($row->failed, $row->total),
            'cancel_rate'   => $this->percentage($row->cancelled, $row->total),
            'total_revenue' => $this->money($row->total_revenue),
        ]);
    }

    /**
     * Each gateway's percentage contribution to total revenue.
     *
     * Returns a Collection ordered by revenue share descending:
     *
     * ```php
     * [
     *   ['gateway' => 'fanbasis',  'revenue' => 24500.00, 'share_pct' => 65.0],
     *   ['gateway' => 'match2pay', 'revenue' => 13200.00, 'share_pct' => 35.0],
     * ]
     * ```
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function revenueContribution(): Collection
    {
        $rows = $this->paidQuery()
            ->selectRaw('gateway, COALESCE(SUM(amount), 0) as revenue')
            ->groupBy('gateway')
            ->orderByDesc('revenue')
            ->get();

        $grandTotal = $rows->sum('revenue');

        return $rows->map(fn ($row) => [
            'gateway'   => $row->gateway,
            'revenue'   => $this->money($row->revenue),
            'share_pct' => $this->percentage($row->revenue, $grandTotal),
        ]);
    }

    /**
     * Webhook reliability per gateway.
     *
     * For each invoice that reached "checkout_initiated" state (logged in lp_payment_logs),
     * checks whether a "webhook_received" event was also logged.
     *
     * A low webhook-received / checkout-initiated ratio may indicate:
     * - Webhook endpoint is unreachable
     * - Gateway is not sending webhooks
     * - Payment abandoned before gateway processes it
     *
     * ```php
     * [
     *   [
     *     'gateway'              => 'fanbasis',
     *     'checkouts_initiated'  => 120,
     *     'webhooks_received'    => 98,
     *     'webhook_rate'         => 81.67,
     *     'failed_signatures'    => 2,
     *   ],
     * ]
     * ```
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function webhookReliability(): Collection
    {
        $logsTable = config('lp_payments.tables.payment_logs', 'lp_payment_logs');

        $rows = $this->logsBaseQuery()
            ->selectRaw('
                gateway,
                SUM(CASE WHEN event = ? THEN 1 ELSE 0 END) as checkouts_initiated,
                SUM(CASE WHEN event = ? THEN 1 ELSE 0 END) as webhooks_received,
                SUM(CASE WHEN event = ? THEN 1 ELSE 0 END) as failed_signatures,
                SUM(CASE WHEN event = ? THEN 1 ELSE 0 END) as checkout_failed
            ', [
                'checkout_initiated',
                'webhook_received',
                'webhook_signature_failed',
                'checkout_failed',
            ])
            ->groupBy('gateway')
            ->orderByDesc('checkouts_initiated')
            ->get();

        return $rows->map(fn ($row) => [
            'gateway'             => $row->gateway,
            'checkouts_initiated' => (int) $row->checkouts_initiated,
            'webhooks_received'   => (int) $row->webhooks_received,
            'checkout_failed'     => (int) $row->checkout_failed,
            'failed_signatures'   => (int) $row->failed_signatures,
            'webhook_rate'        => $this->percentage($row->webhooks_received, $row->checkouts_initiated),
        ]);
    }

    /**
     * Failed checkout attempts per gateway (from payment_logs).
     *
     * A "failed checkout" means the gateway API returned an error before
     * a checkout session was created — different from a "failed payment"
     * which is a webhook-confirmed failure after the user attempted payment.
     *
     * ```php
     * [
     *   ['gateway' => 'rebornpay', 'failed_checkouts' => 8, 'period' => '2025-01'],
     *   ['gateway' => 'fanbasis',  'failed_checkouts' => 2, 'period' => '2025-01'],
     * ]
     * ```
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function failedCheckouts(): Collection
    {
        $period = $this->periodExpression('created_at');

        $rows = $this->logsBaseQuery()
            ->where('event', 'checkout_failed')
            ->selectRaw(
                "{$period} as period,
                gateway,
                COUNT(*) as failed_checkouts"
            )
            ->groupByRaw("{$period}, gateway")
            ->orderByRaw($period)
            ->get();

        return $rows->map(fn ($row) => [
            'period'          => $row->period,
            'gateway'         => $row->gateway,
            'failed_checkouts'=> (int) $row->failed_checkouts,
        ]);
    }

    /**
     * API key rotation audit — which key fingerprint was used per gateway.
     *
     * Returns a Collection showing each unique key_fingerprint (first4****last4) per gateway
     * and how many payments used it. Helps identify whether old keys are still active
     * after a rotation.
     *
     * ```php
     * [
     *   ['gateway' => 'fanbasis', 'key_fingerprint' => 'sk_l****7890', 'payments' => 80, 'first_used' => '2025-01-01', 'last_used' => '2025-06-30'],
     *   ['gateway' => 'fanbasis', 'key_fingerprint' => 'sk_l****abcd', 'payments' => 40, 'first_used' => '2025-07-01', 'last_used' => '2025-12-31'],
     * ]
     * ```
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function keyRotationAudit(): Collection
    {
        $rows = $this->baseQuery()
            ->whereNotNull('key_fingerprint')
            ->selectRaw('
                gateway,
                key_fingerprint,
                COUNT(*) as payments,
                MIN(created_at) as first_used,
                MAX(created_at) as last_used
            ')
            ->groupBy('gateway', 'key_fingerprint')
            ->orderBy('gateway')
            ->orderByDesc('payments')
            ->get();

        return $rows->map(fn ($row) => [
            'gateway'         => $row->gateway,
            'key_fingerprint' => $row->key_fingerprint,
            'payments'        => (int) $row->payments,
            'first_used'      => $row->first_used,
            'last_used'       => $row->last_used,
        ]);
    }
}
