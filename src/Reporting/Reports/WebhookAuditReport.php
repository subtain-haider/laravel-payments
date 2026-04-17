<?php

namespace Subtain\LaravelPayments\Reporting\Reports;

use Illuminate\Support\Collection;
use Subtain\LaravelPayments\Reporting\PaymentReport;

/**
 * Webhook and system audit analytics.
 *
 * Answers questions like:
 *   - Which webhook events fire most frequently?
 *   - Are we receiving duplicate webhooks from any gateway?
 *   - Are there webhooks we can't match to a payment record?
 *   - How do real vs sandbox webhook volumes compare?
 *
 * All queries run against lp_payment_logs.
 *
 * ## Usage
 *
 * ```php
 * $report = PaymentReports::webhookAudit(
 *     ReportingFilters::make()->lastDays(30)
 * );
 *
 * $report->eventFrequency();         // count of each log event type
 * $report->duplicateWebhooks();      // same invoice_id + event appearing twice
 * $report->unmatchedWebhooks();      // logs with payment_id IS NULL
 * $report->sandboxVsReal();          // sandbox vs real log volume
 * $report->signatureFailures();      // webhook_signature_failed events
 * ```
 */
class WebhookAuditReport extends PaymentReport
{
    /**
     * Frequency count of every webhook/log event type.
     *
     * Returns a Collection ordered by count descending:
     *
     * ```php
     * [
     *   ['gateway' => 'fanbasis',  'event' => 'webhook_received',   'count' => 98],
     *   ['gateway' => 'fanbasis',  'event' => 'checkout_initiated', 'count' => 120],
     *   ['gateway' => 'match2pay', 'event' => 'webhook_received',   'count' => 42],
     * ]
     * ```
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function eventFrequency(): Collection
    {
        $rows = $this->logsBaseQuery()
            ->selectRaw('gateway, event, COUNT(*) as cnt')
            ->groupBy('gateway', 'event')
            ->orderByDesc('cnt')
            ->get();

        return $rows->map(fn ($row) => [
            'gateway' => $row->gateway,
            'event'   => $row->event,
            'count'   => (int) $row->cnt,
        ]);
    }

    /**
     * Duplicate webhook detection.
     *
     * Finds cases where the same invoice_id + event combination was logged
     * more than once — a sign of duplicate webhook delivery from the gateway.
     *
     * Payment gateways guarantee at-least-once delivery, so duplicates are normal;
     * this report helps you verify your idempotency handling is working.
     *
     * Returns a Collection ordered by duplicate count descending:
     *
     * ```php
     * [
     *   ['gateway' => 'fanbasis', 'invoice_id' => 'pay_abc123', 'event' => 'webhook_received', 'occurrences' => 3],
     *   ['gateway' => 'match2pay','invoice_id' => 'pay_xyz456', 'event' => 'webhook_received', 'occurrences' => 2],
     * ]
     * ```
     *
     * @param  int  $minimumOccurrences  Only return entries appearing at least this many times (default 2).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function duplicateWebhooks(int $minimumOccurrences = 2): Collection
    {
        $logsTable     = config('lp_payments.tables.payment_logs', 'lp_payment_logs');
        $paymentsTable = config('lp_payments.tables.payments', 'lp_payments');

        $limit = $this->filters->limit ?? 100;

        // Join logs to payments to get the invoice_id (stored on payments, not logs)
        $rows = $this->logsBaseQuery()
            ->join("{$paymentsTable} as p", 'p.id', '=', "{$logsTable}.payment_id")
            ->where('event', 'webhook_received')
            ->whereNotNull("{$logsTable}.payment_id")
            ->selectRaw("
                {$logsTable}.gateway,
                p.invoice_id,
                {$logsTable}.event,
                COUNT(*) as occurrences
            ")
            ->groupBy("{$logsTable}.gateway", 'p.invoice_id', "{$logsTable}.event")
            ->havingRaw('COUNT(*) >= ?', [$minimumOccurrences])
            ->orderByDesc('occurrences')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'gateway'     => $row->gateway,
            'invoice_id'  => $row->invoice_id,
            'event'       => $row->event,
            'occurrences' => (int) $row->occurrences,
        ]);
    }

    /**
     * Unmatched webhooks — log entries with no linked payment record.
     *
     * When `payment_id IS NULL` on a webhook_received log, it means the package
     * received a webhook from the gateway but could not find a matching payment
     * by invoice_id or transaction_id.
     *
     * Common causes:
     * - Webhook arrived before the payment record was created (race condition)
     * - Invoice ID mismatch between what your app sent and what the gateway returned
     * - Duplicate environment webhooks hitting the wrong server
     *
     * ```php
     * [
     *   ['gateway' => 'match2pay', 'count' => 4, 'latest_at' => '2025-11-20 14:22:00'],
     * ]
     * ```
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function unmatchedWebhooks(): Collection
    {
        $rows = $this->logsBaseQuery()
            ->whereNull('payment_id')
            ->where('event', 'webhook_received')
            ->selectRaw('gateway, COUNT(*) as cnt, MAX(created_at) as latest_at')
            ->groupBy('gateway')
            ->orderByDesc('cnt')
            ->get();

        return $rows->map(fn ($row) => [
            'gateway'   => $row->gateway,
            'count'     => (int) $row->cnt,
            'latest_at' => $row->latest_at,
        ]);
    }

    /**
     * Sandbox vs real webhook/log volume.
     *
     * Helps verify that sandbox payments are correctly flagged and that
     * sandbox events are not mixed with real payment data.
     *
     * ```php
     * [
     *   'real'    => ['logs' => 980, 'gateways' => ['fanbasis', 'match2pay']],
     *   'sandbox' => ['logs' => 42,  'gateways' => ['fanbasis']],
     * ]
     * ```
     *
     * @return array<string, mixed>
     */
    public function sandboxVsReal(): array
    {
        $rows = $this->logsBaseQuery()
            ->selectRaw('is_sandbox, COUNT(*) as cnt, COUNT(DISTINCT gateway) as gateway_count')
            ->groupBy('is_sandbox')
            ->get()
            ->keyBy('is_sandbox');

        $real    = $rows->get(0) ?? (object) ['cnt' => 0, 'gateway_count' => 0];
        $sandbox = $rows->get(1) ?? (object) ['cnt' => 0, 'gateway_count' => 0];

        return [
            'real'    => [
                'logs'          => (int) $real->cnt,
                'gateway_count' => (int) $real->gateway_count,
            ],
            'sandbox' => [
                'logs'          => (int) $sandbox->cnt,
                'gateway_count' => (int) $sandbox->gateway_count,
            ],
        ];
    }

    /**
     * Webhook signature verification failures per gateway.
     *
     * A high number of signature failures may indicate:
     * - Misconfigured webhook secret on your end
     * - Gateway changed their signing scheme
     * - Malicious requests (someone trying to spoof webhooks)
     *
     * ```php
     * [
     *   ['gateway' => 'fanbasis', 'failures' => 3, 'latest_at' => '2025-11-15 09:00:00'],
     * ]
     * ```
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function signatureFailures(): Collection
    {
        $rows = $this->logsBaseQuery()
            ->where('event', 'webhook_signature_failed')
            ->selectRaw('gateway, COUNT(*) as failures, MAX(created_at) as latest_at')
            ->groupBy('gateway')
            ->orderByDesc('failures')
            ->get();

        return $rows->map(fn ($row) => [
            'gateway'   => $row->gateway,
            'failures'  => (int) $row->failures,
            'latest_at' => $row->latest_at,
        ]);
    }

    /**
     * Event frequency trend over time, grouped by the configured period.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function eventFrequencyTrend(): Collection
    {
        $period = $this->periodExpression('created_at');

        $rows = $this->logsBaseQuery()
            ->selectRaw(
                "{$period} as period,
                gateway,
                event,
                COUNT(*) as cnt"
            )
            ->groupByRaw("{$period}, gateway, event")
            ->orderByRaw($period)
            ->get();

        return $rows->map(fn ($row) => [
            'period'  => $row->period,
            'gateway' => $row->gateway,
            'event'   => $row->event,
            'count'   => (int) $row->cnt,
        ]);
    }
}
