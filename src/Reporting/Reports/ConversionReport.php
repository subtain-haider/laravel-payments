<?php

namespace Subtain\LaravelPayments\Reporting\Reports;

use Illuminate\Support\Collection;
use Subtain\LaravelPayments\Enums\PaymentStatus;
use Subtain\LaravelPayments\Reporting\PaymentReport;

/**
 * Conversion and payment funnel analytics.
 *
 * Answers questions like:
 *   - What percentage of initiated payments actually succeed?
 *   - Which gateway has the highest failure rate?
 *   - How long does it take from checkout to payment confirmation?
 *   - How many customers attempt payment more than once?
 *
 * ## Usage
 *
 * ```php
 * $report = PaymentReports::conversion(
 *     ReportingFilters::make()->thisMonth()->excludeSandbox()
 * );
 *
 * $report->conversionRate();          // overall initiated → paid rate
 * $report->funnelByStatus();          // breakdown across all statuses
 * $report->failureRateByGateway();    // failure rate per gateway
 * $report->cancellationRate();        // cancellation rate over time
 * $report->repeatAttempts();          // customers who tried multiple times
 * $report->checkoutToPaidTime();      // avg/min/max latency in minutes
 * ```
 */
class ConversionReport extends PaymentReport
{
    /**
     * Overall payment conversion rate.
     *
     * Measures how many initiated payments (status moved to processing or beyond)
     * ultimately resulted in a successful payment.
     *
     * ```php
     * [
     *   'initiated'       => 120,     // total payments that reached processing
     *   'paid'            => 98,      // payments that reached paid
     *   'conversion_rate' => 81.67,   // paid / initiated * 100
     *   'failed'          => 14,
     *   'cancelled'       => 8,
     * ]
     * ```
     *
     * @return array<string, int|float>
     */
    public function conversionRate(): array
    {
        $rows = $this->baseQuery()
            ->whereIn('status', [
                PaymentStatus::PROCESSING->value,
                PaymentStatus::PAID->value,
                PaymentStatus::FAILED->value,
                PaymentStatus::CANCELLED->value,
                PaymentStatus::REFUNDED->value,
            ])
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $paid      = (int) ($rows->get(PaymentStatus::PAID->value)->cnt ?? 0);
        $failed    = (int) ($rows->get(PaymentStatus::FAILED->value)->cnt ?? 0);
        $cancelled = (int) ($rows->get(PaymentStatus::CANCELLED->value)->cnt ?? 0);
        $refunded  = (int) ($rows->get(PaymentStatus::REFUNDED->value)->cnt ?? 0);
        $processing= (int) ($rows->get(PaymentStatus::PROCESSING->value)->cnt ?? 0);
        $initiated = $paid + $failed + $cancelled + $processing + $refunded;

        return [
            'initiated'        => $initiated,
            'paid'             => $paid,
            'failed'           => $failed,
            'cancelled'        => $cancelled,
            'refunded'         => $refunded,
            'still_processing' => $processing,
            'conversion_rate'  => $this->percentage($paid, $initiated),
        ];
    }

    /**
     * Full payment status funnel — every status with count and percentage of total.
     *
     * Returns a Collection ordered by count descending:
     *
     * ```php
     * [
     *   ['status' => 'paid',       'count' => 98,  'pct' => 65.33],
     *   ['status' => 'pending',    'count' => 22,  'pct' => 14.67],
     *   ['status' => 'failed',     'count' => 14,  'pct' => 9.33],
     *   ['status' => 'cancelled',  'count' => 8,   'pct' => 5.33],
     *   ...
     * ]
     * ```
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function funnelByStatus(): Collection
    {
        $rows = $this->baseQuery()
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->orderByDesc('cnt')
            ->get();

        $total = $rows->sum('cnt');

        return $rows->map(fn ($row) => [
            'status' => $row->status,
            'count'  => (int) $row->cnt,
            'pct'    => $this->percentage($row->cnt, $total),
        ]);
    }

    /**
     * Failure rate per gateway.
     *
     * Returns a Collection ordered by failure rate descending:
     *
     * ```php
     * [
     *   ['gateway' => 'rebornpay', 'total' => 40, 'failed' => 12, 'failure_rate' => 30.0],
     *   ['gateway' => 'fanbasis',  'total' => 80, 'failed' => 8,  'failure_rate' => 10.0],
     * ]
     * ```
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function failureRateByGateway(): Collection
    {
        $rows = $this->baseQuery()
            ->selectRaw('
                gateway,
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as paid,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled
            ', [
                PaymentStatus::FAILED->value,
                PaymentStatus::PAID->value,
                PaymentStatus::CANCELLED->value,
            ])
            ->groupBy('gateway')
            ->orderByDesc('failed')
            ->get();

        return $rows->map(fn ($row) => [
            'gateway'      => $row->gateway,
            'total'        => (int) $row->total,
            'paid'         => (int) $row->paid,
            'failed'       => (int) $row->failed,
            'cancelled'    => (int) $row->cancelled,
            'failure_rate' => $this->percentage($row->failed, $row->total),
            'success_rate' => $this->percentage($row->paid, $row->total),
        ]);
    }

    /**
     * Cancellation rate over time, grouped by the configured period.
     *
     * Returns a Collection ordered chronologically:
     *
     * ```php
     * [
     *   ['period' => '2025-01', 'total' => 50, 'cancelled' => 5, 'cancellation_rate' => 10.0],
     *   ['period' => '2025-02', 'total' => 60, 'cancelled' => 3, 'cancellation_rate' => 5.0],
     * ]
     * ```
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function cancellationRate(): Collection
    {
        $period = $this->periodExpression('created_at');

        $rows = $this->baseQuery()
            ->selectRaw(
                "{$period} as period,
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled",
                [PaymentStatus::CANCELLED->value]
            )
            ->groupByRaw($period)
            ->orderByRaw($period)
            ->get();

        return $rows->map(fn ($row) => [
            'period'            => $row->period,
            'total'             => (int) $row->total,
            'cancelled'         => (int) $row->cancelled,
            'cancellation_rate' => $this->percentage($row->cancelled, $row->total),
        ]);
    }

    /**
     * Customers who have attempted payment more than once.
     *
     * Useful for identifying friction points where customers retry after failure.
     * Returns a Collection ordered by attempt count descending:
     *
     * ```php
     * [
     *   ['customer_email' => 'user@example.com', 'attempts' => 4, 'paid' => 1, 'failed' => 3],
     *   ['customer_email' => 'other@example.com', 'attempts' => 2, 'paid' => 1, 'failed' => 1],
     * ]
     * ```
     *
     * @param  int  $minimumAttempts  Only return customers with at least this many attempts (default 2).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function repeatAttempts(int $minimumAttempts = 2): Collection
    {
        $limit = $this->filters->limit ?? 100;

        $rows = $this->baseQuery()
            ->whereNotNull('customer_email')
            ->selectRaw('
                customer_email,
                COUNT(*) as attempts,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as paid,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled
            ', [
                PaymentStatus::PAID->value,
                PaymentStatus::FAILED->value,
                PaymentStatus::CANCELLED->value,
            ])
            ->groupBy('customer_email')
            ->havingRaw('COUNT(*) >= ?', [$minimumAttempts])
            ->orderByDesc('attempts')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'customer_email' => $row->customer_email,
            'attempts'       => (int) $row->attempts,
            'paid'           => (int) $row->paid,
            'failed'         => (int) $row->failed,
            'cancelled'      => (int) $row->cancelled,
        ]);
    }

    /**
     * Time elapsed between payment initiation and confirmation (checkout-to-paid latency).
     *
     * Measures the time from created_at (checkout initiated) to paid_at (webhook confirmed).
     * Only includes payments that have both fields set (i.e. actually paid).
     *
     * ```php
     * [
     *   'avg_minutes' => 3.5,
     *   'min_minutes' => 0.2,
     *   'max_minutes' => 47.8,
     *   'sample_size' => 98,
     * ]
     * ```
     *
     * @return array<string, float|int>
     */
    public function checkoutToPaidTime(): array
    {
        $driver = \Illuminate\Support\Facades\DB::getDriverName();

        // Compute diff in seconds using DB-agnostic approach
        $diffExpr = match ($driver) {
            'sqlite' => 'AVG((julianday(paid_at) - julianday(created_at)) * 86400)',
            'pgsql'  => 'AVG(EXTRACT(EPOCH FROM (paid_at - created_at)))',
            default  => 'AVG(TIMESTAMPDIFF(SECOND, created_at, paid_at))',
        };

        $minExpr = match ($driver) {
            'sqlite' => 'MIN((julianday(paid_at) - julianday(created_at)) * 86400)',
            'pgsql'  => 'MIN(EXTRACT(EPOCH FROM (paid_at - created_at)))',
            default  => 'MIN(TIMESTAMPDIFF(SECOND, created_at, paid_at))',
        };

        $maxExpr = match ($driver) {
            'sqlite' => 'MAX((julianday(paid_at) - julianday(created_at)) * 86400)',
            'pgsql'  => 'MAX(EXTRACT(EPOCH FROM (paid_at - created_at)))',
            default  => 'MAX(TIMESTAMPDIFF(SECOND, created_at, paid_at))',
        };

        $row = $this->baseQuery()
            ->where('status', PaymentStatus::PAID->value)
            ->whereNotNull('paid_at')
            ->selectRaw(
                "{$diffExpr} as avg_seconds,
                {$minExpr} as min_seconds,
                {$maxExpr} as max_seconds,
                COUNT(*) as sample_size"
            )
            ->first();

        $toMinutes = fn ($seconds) => $seconds !== null ? round((float) $seconds / 60, 2) : null;

        return [
            'avg_minutes' => $toMinutes($row->avg_seconds ?? null),
            'min_minutes' => $toMinutes($row->min_seconds ?? null),
            'max_minutes' => $toMinutes($row->max_seconds ?? null),
            'sample_size' => (int) ($row->sample_size ?? 0),
        ];
    }

    /**
     * Conversion rate trend over time, grouped by the configured period.
     *
     * Shows how initiation-to-paid conversion changes across periods.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function conversionTrend(): Collection
    {
        $period = $this->periodExpression('created_at');

        $rows = $this->baseQuery()
            ->selectRaw(
                "{$period} as period,
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as paid,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed",
                [
                    PaymentStatus::PAID->value,
                    PaymentStatus::FAILED->value,
                ]
            )
            ->groupByRaw($period)
            ->orderByRaw($period)
            ->get();

        return $rows->map(fn ($row) => [
            'period'          => $row->period,
            'total'           => (int) $row->total,
            'paid'            => (int) $row->paid,
            'failed'          => (int) $row->failed,
            'conversion_rate' => $this->percentage($row->paid, $row->total),
        ]);
    }
}
