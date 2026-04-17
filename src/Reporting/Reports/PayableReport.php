<?php

namespace Subtain\LaravelPayments\Reporting\Reports;

use Illuminate\Support\Collection;
use Subtain\LaravelPayments\Enums\PaymentStatus;
use Subtain\LaravelPayments\Reporting\PaymentReport;

/**
 * Payable-type analytics — revenue and payments grouped by what was purchased.
 *
 * The `lp_payments` table uses a polymorphic `payable_type` / `payable_id` to
 * link each payment to whatever model it belongs to in the consuming application
 * (e.g. `App\Models\Order`, `App\Models\Subscription`, `App\Models\Invoice`).
 *
 * This report lets you slice revenue and payment data by product/model type.
 *
 * ## Usage
 *
 * ```php
 * $report = PaymentReports::payable(
 *     ReportingFilters::make()->thisYear()->excludeSandbox()
 * );
 *
 * $report->byType();              // revenue and payments grouped by payable_type
 * $report->typeShareOfRevenue();  // each type's % of total revenue
 * $report->trend();               // payments per type per time period
 * $report->unlinkedPayments();    // payments with no payable (payable_type IS NULL)
 * ```
 */
class PayableReport extends PaymentReport
{
    /**
     * Revenue and payment counts grouped by payable_type.
     *
     * Returns a Collection ordered by revenue descending:
     *
     * ```php
     * [
     *   [
     *     'payable_type'  => 'App\Models\Order',
     *     'payments'      => 280,
     *     'total_revenue' => 28000.00,
     *     'aov'           => 100.00,
     *   ],
     *   [
     *     'payable_type'  => 'App\Models\Subscription',
     *     'payments'      => 40,
     *     'total_revenue' => 3200.00,
     *     'aov'           => 80.00,
     *   ],
     * ]
     * ```
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function byType(): Collection
    {
        $rows = $this->paidQuery()
            ->selectRaw('
                COALESCE(payable_type, \'(unlinked)\') as payable_type,
                COUNT(*) as payments,
                COALESCE(SUM(amount), 0) as total_revenue
            ')
            ->groupBy('payable_type')
            ->orderByDesc('total_revenue')
            ->get();

        return $rows->map(fn ($row) => [
            'payable_type'  => $row->payable_type,
            'payments'      => (int) $row->payments,
            'total_revenue' => $this->money($row->total_revenue),
            'aov'           => (int) $row->payments > 0
                ? $this->money($row->total_revenue / $row->payments)
                : 0.0,
        ]);
    }

    /**
     * Each payable type's percentage share of total revenue.
     *
     * ```php
     * [
     *   ['payable_type' => 'App\Models\Order',        'revenue' => 28000.00, 'share_pct' => 89.74],
     *   ['payable_type' => 'App\Models\Subscription', 'revenue' => 3200.00,  'share_pct' => 10.26],
     * ]
     * ```
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function typeShareOfRevenue(): Collection
    {
        $rows = $this->paidQuery()
            ->selectRaw('
                COALESCE(payable_type, \'(unlinked)\') as payable_type,
                COALESCE(SUM(amount), 0) as revenue
            ')
            ->groupBy('payable_type')
            ->orderByDesc('revenue')
            ->get();

        $grandTotal = $rows->sum('revenue');

        return $rows->map(fn ($row) => [
            'payable_type' => $row->payable_type,
            'revenue'      => $this->money($row->revenue),
            'share_pct'    => $this->percentage($row->revenue, $grandTotal),
        ]);
    }

    /**
     * Payment counts per payable type per time period.
     *
     * Returns a Collection ordered by period then by payment count:
     *
     * ```php
     * [
     *   ['period' => '2025-01', 'payable_type' => 'App\Models\Order',        'payments' => 45, 'revenue' => 4500.00],
     *   ['period' => '2025-01', 'payable_type' => 'App\Models\Subscription', 'payments' => 5,  'revenue' => 400.00],
     * ]
     * ```
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function trend(): Collection
    {
        $period = $this->periodExpression('created_at');

        $rows = $this->paidQuery()
            ->selectRaw(
                "{$period} as period,
                COALESCE(payable_type, '(unlinked)') as payable_type,
                COUNT(*) as payments,
                COALESCE(SUM(amount), 0) as revenue"
            )
            ->groupByRaw("{$period}, payable_type")
            ->orderByRaw($period)
            ->orderByDesc('payments')
            ->get();

        return $rows->map(fn ($row) => [
            'period'       => $row->period,
            'payable_type' => $row->payable_type,
            'payments'     => (int) $row->payments,
            'revenue'      => $this->money($row->revenue),
        ]);
    }

    /**
     * Payments with no linked payable model (payable_type IS NULL).
     *
     * These are payments created without associating them with any model —
     * either via lightweight gateway calls (without PaymentService::initiate)
     * or as orphaned records from deleted payable models.
     *
     * ```php
     * [
     *   'count'    => 12,
     *   'revenue'  => 600.00,
     *   'gateways' => ['fanbasis', 'match2pay'],
     * ]
     * ```
     *
     * @return array<string, mixed>
     */
    public function unlinkedPayments(): array
    {
        $row = $this->paidQuery()
            ->whereNull('payable_type')
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(amount), 0) as revenue')
            ->first();

        $gateways = $this->paidQuery()
            ->whereNull('payable_type')
            ->distinct()
            ->pluck('gateway')
            ->values()
            ->toArray();

        return [
            'count'    => (int) ($row->cnt ?? 0),
            'revenue'  => $this->money($row->revenue ?? 0),
            'gateways' => $gateways,
        ];
    }

    /**
     * Per-gateway revenue breakdown scoped to a specific payable type.
     *
     * Useful for answering: "For orders, which gateway generated the most revenue?"
     *
     * @param  string  $payableType  Full class name, e.g. 'App\Models\Order'.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function byGatewayForType(string $payableType): Collection
    {
        $rows = $this->paidQuery()
            ->where('payable_type', $payableType)
            ->selectRaw('
                gateway,
                COUNT(*) as payments,
                COALESCE(SUM(amount), 0) as revenue
            ')
            ->groupBy('gateway')
            ->orderByDesc('revenue')
            ->get();

        $total = $rows->sum('revenue');

        return $rows->map(fn ($row) => [
            'gateway'   => $row->gateway,
            'payments'  => (int) $row->payments,
            'revenue'   => $this->money($row->revenue),
            'share_pct' => $this->percentage($row->revenue, $total),
        ]);
    }
}
