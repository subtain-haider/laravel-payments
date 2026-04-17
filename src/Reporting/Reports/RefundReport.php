<?php

namespace Subtain\LaravelPayments\Reporting\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Subtain\LaravelPayments\Enums\PaymentStatus;
use Subtain\LaravelPayments\Reporting\PaymentReport;

/**
 * Refund analytics.
 *
 * Answers questions like:
 *   - How much money have we refunded?
 *   - What is our refund rate per gateway?
 *   - How quickly do we process refunds?
 *
 * ## Usage
 *
 * ```php
 * $report = PaymentReports::refund(
 *     ReportingFilters::make()->thisYear()->excludeSandbox()
 * );
 *
 * $report->summary();            // total refunded amount + count
 * $report->rateByGateway();      // refund rate per gateway
 * $report->trend();              // refunds per time period
 * $report->timeToRefund();       // avg/min/max time between paid_at and refunded_at
 * ```
 */
class RefundReport extends PaymentReport
{
    /**
     * Overall refund summary.
     *
     * ```php
     * [
     *   'total_refunded'      => 5,
     *   'total_amount'        => 1250.00,
     *   'refund_rate'         => 5.10,    // refunded / paid * 100
     * ]
     * ```
     *
     * @return array<string, float|int>
     */
    public function summary(): array
    {
        $refunded = $this->baseQuery()
            ->where('status', PaymentStatus::REFUNDED->value)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total')
            ->first();

        $paid = $this->baseQuery()
            ->where('status', PaymentStatus::PAID->value)
            ->count();

        $count  = (int) ($refunded->cnt ?? 0);
        $total  = $this->money($refunded->total ?? 0);

        return [
            'total_refunded'  => $count,
            'total_amount'    => $total,
            'refund_rate'     => $this->percentage($count, $paid),
        ];
    }

    /**
     * Refund rate broken down per gateway.
     *
     * ```php
     * [
     *   ['gateway' => 'fanbasis',  'paid' => 98, 'refunded' => 4, 'refund_rate' => 4.08, 'refunded_amount' => 800.00],
     *   ['gateway' => 'match2pay', 'paid' => 40, 'refunded' => 1, 'refund_rate' => 2.50, 'refunded_amount' => 250.00],
     * ]
     * ```
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rateByGateway(): Collection
    {
        $rows = $this->baseQuery()
            ->selectRaw('
                gateway,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as paid,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as refunded,
                COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) as refunded_amount
            ', [
                PaymentStatus::PAID->value,
                PaymentStatus::REFUNDED->value,
                PaymentStatus::REFUNDED->value,
            ])
            ->groupBy('gateway')
            ->orderByDesc('refunded')
            ->get();

        return $rows->map(fn ($row) => [
            'gateway'         => $row->gateway,
            'paid'            => (int) $row->paid,
            'refunded'        => (int) $row->refunded,
            'refunded_amount' => $this->money($row->refunded_amount),
            'refund_rate'     => $this->percentage($row->refunded, $row->paid),
        ]);
    }

    /**
     * Refund trend over time, grouped by the configured period.
     *
     * Uses `refunded_at` for the time axis if available; falls back to `created_at`.
     *
     * ```php
     * [
     *   ['period' => '2025-01', 'refunded' => 2, 'refunded_amount' => 500.00],
     *   ['period' => '2025-02', 'refunded' => 1, 'refunded_amount' => 250.00],
     * ]
     * ```
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function trend(): Collection
    {
        // refunded_at is always present — use it as the time axis so trends
        // reflect when the refund actually happened, not when the payment was created.
        $period = $this->periodExpression('refunded_at');

        $rows = $this->baseQuery()
            ->where('status', PaymentStatus::REFUNDED->value)
            ->whereNotNull('refunded_at')
            ->selectRaw(
                "{$period} as period,
                COUNT(*) as refunded,
                COALESCE(SUM(amount), 0) as refunded_amount"
            )
            ->groupByRaw($period)
            ->orderByRaw($period)
            ->get();

        return $rows->map(fn ($row) => [
            'period'          => $row->period,
            'refunded'        => (int) $row->refunded,
            'refunded_amount' => $this->money($row->refunded_amount),
        ]);
    }

    /**
     * Time to refund — latency between payment confirmation and refund.
     *
     * Measures the hours elapsed from paid_at to refunded_at.
     * The refunded_at column is always present (included in the main create migration).
     *
     * ```php
     * [
     *   'avg_hours'   => 4.5,
     *   'min_hours'   => 0.1,
     *   'max_hours'   => 72.0,
     *   'sample_size' => 5,
     * ]
     * ```
     *
     * @return array<string, float|int|null>
     */
    public function timeToRefund(): array
    {
        $driver = DB::getDriverName();

        $diffExpr = match ($driver) {
            'sqlite' => 'AVG((julianday(refunded_at) - julianday(paid_at)) * 24)',
            'pgsql'  => 'AVG(EXTRACT(EPOCH FROM (refunded_at - paid_at)) / 3600)',
            default  => 'AVG(TIMESTAMPDIFF(MINUTE, paid_at, refunded_at) / 60)',
        };

        $minExpr = match ($driver) {
            'sqlite' => 'MIN((julianday(refunded_at) - julianday(paid_at)) * 24)',
            'pgsql'  => 'MIN(EXTRACT(EPOCH FROM (refunded_at - paid_at)) / 3600)',
            default  => 'MIN(TIMESTAMPDIFF(MINUTE, paid_at, refunded_at) / 60)',
        };

        $maxExpr = match ($driver) {
            'sqlite' => 'MAX((julianday(refunded_at) - julianday(paid_at)) * 24)',
            'pgsql'  => 'MAX(EXTRACT(EPOCH FROM (refunded_at - paid_at)) / 3600)',
            default  => 'MAX(TIMESTAMPDIFF(MINUTE, paid_at, refunded_at) / 60)',
        };

        $row = $this->baseQuery()
            ->where('status', PaymentStatus::REFUNDED->value)
            ->whereNotNull('paid_at')
            ->whereNotNull('refunded_at')
            ->selectRaw(
                "{$diffExpr} as avg_hours,
                {$minExpr} as min_hours,
                {$maxExpr} as max_hours,
                COUNT(*) as sample_size"
            )
            ->first();

        return [
            'avg_hours'   => $row->avg_hours !== null ? round((float) $row->avg_hours, 2) : null,
            'min_hours'   => $row->min_hours !== null ? round((float) $row->min_hours, 2) : null,
            'max_hours'   => $row->max_hours !== null ? round((float) $row->max_hours, 2) : null,
            'sample_size' => (int) ($row->sample_size ?? 0),
        ];
    }
}
