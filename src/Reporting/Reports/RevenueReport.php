<?php

namespace Subtain\LaravelPayments\Reporting\Reports;

use Illuminate\Support\Collection;
use Subtain\LaravelPayments\Enums\PaymentStatus;
use Subtain\LaravelPayments\Reporting\PaymentReport;
use Subtain\LaravelPayments\Reporting\ReportingFilters;

/**
 * Revenue analytics report.
 *
 * Answers questions like:
 *   - How much money did we make?
 *   - Which gateway generated the most revenue?
 *   - What is our average order value?
 *   - How does revenue trend over time?
 *   - How much did we lose to discounts?
 *
 * ## Usage
 *
 * ```php
 * $report = PaymentReports::revenue(
 *     ReportingFilters::make()->thisYear()->excludeSandbox()
 * );
 *
 * $report->summary();         // totals + AOV
 * $report->byGateway();       // per-gateway breakdown
 * $report->byCurrency();      // per-currency totals
 * $report->trend();           // revenue per period
 * $report->grossVsNet();      // gross revenue vs after-discount net
 * ```
 */
class RevenueReport extends PaymentReport
{
    /**
     * High-level revenue summary.
     *
     * Returns a single flat array with all key revenue metrics:
     *
     * - `total_revenue`      — sum of paid amounts (net of discounts)
     * - `gross_revenue`      — sum of amounts before discounts (amount + discount_amount)
     * - `total_discount`     — sum of discount_amount on paid payments
     * - `total_payments`     — count of paid payments
     * - `aov`                — average order value (net)
     * - `sandbox_revenue`    — revenue from sandboxed payments (informational)
     * - `sandbox_payments`   — count of sandboxed payments
     *
     * @return array<string, float|int>
     */
    public function summary(): array
    {
        $paid = $this->paidQuery()
            ->selectRaw('
                COUNT(*) as total_payments,
                COALESCE(SUM(amount), 0) as total_revenue,
                COALESCE(SUM(COALESCE(discount_amount, 0)), 0) as total_discount
            ')
            ->first();

        $totalRevenue   = $this->money($paid->total_revenue ?? 0);
        $totalDiscount  = $this->money($paid->total_discount ?? 0);
        $totalPayments  = (int) ($paid->total_payments ?? 0);
        $grossRevenue   = $this->money($totalRevenue + $totalDiscount);

        // Sandbox stats (always queried regardless of sandbox filter, for informational purposes)
        $sandbox = $this->baseQuery()
            ->where('status', PaymentStatus::PAID->value)
            ->where('is_sandbox', true)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(amount), 0) as rev')
            ->first();

        return [
            'total_revenue'    => $totalRevenue,
            'gross_revenue'    => $grossRevenue,
            'total_discount'   => $totalDiscount,
            'total_payments'   => $totalPayments,
            'aov'              => $totalPayments > 0 ? $this->money($totalRevenue / $totalPayments) : 0.0,
            'sandbox_revenue'  => $this->money($sandbox->rev ?? 0),
            'sandbox_payments' => (int) ($sandbox->cnt ?? 0),
        ];
    }

    /**
     * Revenue broken down by payment gateway.
     *
     * Returns a Collection of items, one per gateway, ordered by revenue descending:
     *
     * ```php
     * [
     *   ['gateway' => 'fanbasis',  'total_revenue' => 9800.00, 'payments' => 42, 'aov' => 233.33, 'share_pct' => 65.0],
     *   ['gateway' => 'match2pay', 'total_revenue' => 5200.00, 'payments' => 18, 'aov' => 288.89, 'share_pct' => 35.0],
     * ]
     * ```
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function byGateway(): Collection
    {
        $rows = $this->paidQuery()
            ->selectRaw('
                gateway,
                COUNT(*) as payments,
                COALESCE(SUM(amount), 0) as total_revenue,
                COALESCE(SUM(COALESCE(discount_amount, 0)), 0) as total_discount
            ')
            ->groupBy('gateway')
            ->orderByDesc('total_revenue')
            ->get();

        $grandTotal = $rows->sum('total_revenue');

        return $rows->map(function ($row) use ($grandTotal) {
            $revenue   = $this->money($row->total_revenue);
            $discount  = $this->money($row->total_discount);
            $payments  = (int) $row->payments;

            return [
                'gateway'       => $row->gateway,
                'total_revenue' => $revenue,
                'gross_revenue' => $this->money($revenue + $discount),
                'total_discount'=> $discount,
                'payments'      => $payments,
                'aov'           => $payments > 0 ? $this->money($revenue / $payments) : 0.0,
                'share_pct'     => $this->percentage($revenue, $grandTotal),
            ];
        });
    }

    /**
     * Revenue broken down by currency.
     *
     * Returns a Collection ordered by total revenue descending.
     *
     * ```php
     * [
     *   ['currency' => 'USD', 'total_revenue' => 14000.00, 'payments' => 58],
     *   ['currency' => 'EUR', 'total_revenue' => 1000.00,  'payments' => 4],
     * ]
     * ```
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function byCurrency(): Collection
    {
        $rows = $this->paidQuery()
            ->selectRaw('
                currency,
                COUNT(*) as payments,
                COALESCE(SUM(amount), 0) as total_revenue
            ')
            ->groupBy('currency')
            ->orderByDesc('total_revenue')
            ->get();

        return $rows->map(fn ($row) => [
            'currency'      => $row->currency,
            'total_revenue' => $this->money($row->total_revenue),
            'payments'      => (int) $row->payments,
            'aov'           => (int) $row->payments > 0
                ? $this->money($row->total_revenue / $row->payments)
                : 0.0,
        ]);
    }

    /**
     * Revenue trend over time, grouped by the configured period.
     *
     * Returns a Collection ordered chronologically, one entry per period:
     *
     * ```php
     * [
     *   ['period' => '2025-01', 'total_revenue' => 4200.00, 'payments' => 17, 'aov' => 247.06],
     *   ['period' => '2025-02', 'total_revenue' => 5100.00, 'payments' => 21, 'aov' => 242.86],
     * ]
     * ```
     *
     * Control the granularity via `ReportingFilters::groupBy('day'|'week'|'month'|'quarter'|'year')`.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function trend(): Collection
    {
        $period = $this->periodExpression('created_at');

        $rows = $this->paidQuery()
            ->selectRaw("{$period} as period, COUNT(*) as payments, COALESCE(SUM(amount), 0) as total_revenue")
            ->groupByRaw($period)
            ->orderByRaw($period)
            ->get();

        return $rows->map(fn ($row) => [
            'period'        => $row->period,
            'total_revenue' => $this->money($row->total_revenue),
            'payments'      => (int) $row->payments,
            'aov'           => (int) $row->payments > 0
                ? $this->money($row->total_revenue / $row->payments)
                : 0.0,
        ]);
    }

    /**
     * Gross revenue vs net revenue comparison, grouped by period.
     *
     * Gross = amount charged before discount.
     * Net   = amount actually paid (what you receive).
     * Discount = difference between gross and net.
     *
     * ```php
     * [
     *   [
     *     'period'         => '2025-01',
     *     'gross_revenue'  => 5200.00,
     *     'net_revenue'    => 4800.00,
     *     'total_discount' => 400.00,
     *     'discount_rate'  => 7.69,   // % of gross
     *   ],
     * ]
     * ```
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function grossVsNet(): Collection
    {
        $period = $this->periodExpression('created_at');

        $rows = $this->paidQuery()
            ->selectRaw(
                "{$period} as period,
                COALESCE(SUM(amount), 0) as net_revenue,
                COALESCE(SUM(COALESCE(discount_amount, 0)), 0) as total_discount"
            )
            ->groupByRaw($period)
            ->orderByRaw($period)
            ->get();

        return $rows->map(function ($row) {
            $net      = $this->money($row->net_revenue);
            $discount = $this->money($row->total_discount);
            $gross    = $this->money($net + $discount);

            return [
                'period'         => $row->period,
                'gross_revenue'  => $gross,
                'net_revenue'    => $net,
                'total_discount' => $discount,
                'discount_rate'  => $this->percentage($discount, $gross),
            ];
        });
    }

    /**
     * Revenue split by real vs sandbox payments.
     *
     * Useful for verifying that sandbox mode is working correctly and
     * ensuring sandbox revenue is never mixed with real reporting.
     *
     * @return array<string, mixed>
     */
    public function sandboxVsReal(): array
    {
        $table = config('lp_payments.tables.payments', 'lp_payments');

        $rows = $this->baseQuery()
            ->where('status', PaymentStatus::PAID->value)
            ->selectRaw('
                is_sandbox,
                COUNT(*) as payments,
                COALESCE(SUM(amount), 0) as total_revenue
            ')
            ->groupBy('is_sandbox')
            ->get()
            ->keyBy('is_sandbox');

        $real    = $rows->get(0) ?? (object) ['payments' => 0, 'total_revenue' => 0];
        $sandbox = $rows->get(1) ?? (object) ['payments' => 0, 'total_revenue' => 0];

        return [
            'real'    => [
                'payments'      => (int) $real->payments,
                'total_revenue' => $this->money($real->total_revenue),
            ],
            'sandbox' => [
                'payments'      => (int) $sandbox->payments,
                'total_revenue' => $this->money($sandbox->total_revenue),
            ],
        ];
    }

    /**
     * Average order value per gateway, optionally grouped by time period.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function aovByGateway(): Collection
    {
        $rows = $this->paidQuery()
            ->selectRaw('
                gateway,
                COUNT(*) as payments,
                COALESCE(AVG(amount), 0) as aov,
                COALESCE(MIN(amount), 0) as min_amount,
                COALESCE(MAX(amount), 0) as max_amount
            ')
            ->groupBy('gateway')
            ->orderByDesc('aov')
            ->get();

        return $rows->map(fn ($row) => [
            'gateway'    => $row->gateway,
            'payments'   => (int) $row->payments,
            'aov'        => $this->money($row->aov),
            'min_amount' => $this->money($row->min_amount),
            'max_amount' => $this->money($row->max_amount),
        ]);
    }
}
