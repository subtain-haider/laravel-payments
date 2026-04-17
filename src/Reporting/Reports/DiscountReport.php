<?php

namespace Subtain\LaravelPayments\Reporting\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Subtain\LaravelPayments\Enums\PaymentStatus;
use Subtain\LaravelPayments\Reporting\PaymentReport;

/**
 * Discount and coupon code analytics.
 *
 * Answers questions like:
 *   - Which discount codes are used the most?
 *   - How much revenue are we losing to discounts?
 *   - Which codes are about to expire?
 *   - Which gateway do discount users prefer?
 *
 * ## Usage
 *
 * ```php
 * $report = PaymentReports::discount(
 *     ReportingFilters::make()->thisYear()->excludeSandbox()
 * );
 *
 * $report->summary();            // overall discount totals
 * $report->topCodes();           // most used codes
   * $report->revenueLost();        // total discount_amount given away
 * $report->perCodePerformance(); // per-code stats: uses, saved, gateway breakdown
 * $report->expiryAnalysis();     // codes expiring soon or already expired
 * $report->discountedVsFull();   // conversion comparison: with vs without discount
 * ```
 */
class DiscountReport extends PaymentReport
{
    /**
     * Overall discount summary across all paid payments.
     *
     * ```php
     * [
     *   'total_discounted_payments' => 42,
     *   'total_revenue_lost'        => 1260.00,  // sum of discount_amount
     *   'avg_discount_per_payment'  => 30.00,
     *   'discount_rate_of_total'    => 12.5,     // % of all paid payments that used a discount
     * ]
     * ```
     *
     * @return array<string, float|int>
     */
    public function summary(): array
    {
        $all = $this->paidQuery()
            ->selectRaw('
                COUNT(*) as total_paid,
                SUM(CASE WHEN discount_code_id IS NOT NULL THEN 1 ELSE 0 END) as discounted_count,
                COALESCE(SUM(COALESCE(discount_amount, 0)), 0) as total_revenue_lost
            ')
            ->first();

        $totalPaid       = (int) ($all->total_paid ?? 0);
        $discountedCount = (int) ($all->discounted_count ?? 0);
        $revenueLost     = $this->money($all->total_revenue_lost ?? 0);

        return [
            'total_discounted_payments' => $discountedCount,
            'total_revenue_lost'        => $revenueLost,
            'avg_discount_per_payment'  => $discountedCount > 0
                ? $this->money($revenueLost / $discountedCount)
                : 0.0,
            'discount_rate_of_total'    => $this->percentage($discountedCount, $totalPaid),
        ];
    }

    /**
     * Top N most used discount codes, ordered by usage count.
     *
     * Joins lp_discount_codes with lp_payments to get usage stats per code.
     *
     * ```php
     * [
     *   ['code' => 'LAUNCH20', 'uses' => 28, 'revenue_lost' => 840.00, 'avg_discount' => 30.00],
     *   ['code' => 'VIP50',    'uses' => 14, 'revenue_lost' => 420.00, 'avg_discount' => 30.00],
     * ]
     * ```
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function topCodes(): Collection
    {
        $paymentsTable = config('lp_payments.tables.payments', 'lp_payments');
        $codesTable    = config('lp_payments.tables.discount_codes', 'lp_discount_codes');

        $limit = $this->filters->limit ?? 20;

        $query = $this->paidQuery()
            ->join("{$codesTable} as dc", 'dc.id', '=', "{$paymentsTable}.discount_code_id")
            ->whereNotNull("{$paymentsTable}.discount_code_id")
            ->selectRaw("
                dc.code,
                dc.type,
                dc.value,
                COUNT(*) as uses,
                COALESCE(SUM({$paymentsTable}.discount_amount), 0) as revenue_lost,
                COALESCE(AVG({$paymentsTable}.discount_amount), 0) as avg_discount
            ")
            ->groupBy('dc.code', 'dc.type', 'dc.value')
            ->orderByDesc('uses')
            ->limit($limit);

        return $query->get()->map(fn ($row) => [
            'code'          => $row->code,
            'type'          => $row->type,
            'value'         => (float) $row->value,
            'uses'          => (int) $row->uses,
            'revenue_lost'  => $this->money($row->revenue_lost),
            'avg_discount'  => $this->money($row->avg_discount),
        ]);
    }

    /**
     * Revenue lost to discounts, grouped by time period.
     *
     * ```php
     * [
     *   ['period' => '2025-01', 'revenue_lost' => 420.00, 'discounted_payments' => 14],
     *   ['period' => '2025-02', 'revenue_lost' => 540.00, 'discounted_payments' => 18],
     * ]
     * ```
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function revenueLost(): Collection
    {
        $period = $this->periodExpression('created_at');

        $rows = $this->paidQuery()
            ->whereNotNull('discount_code_id')
            ->selectRaw(
                "{$period} as period,
                COUNT(*) as discounted_payments,
                COALESCE(SUM(discount_amount), 0) as revenue_lost"
            )
            ->groupByRaw($period)
            ->orderByRaw($period)
            ->get();

        return $rows->map(fn ($row) => [
            'period'               => $row->period,
            'discounted_payments'  => (int) $row->discounted_payments,
            'revenue_lost'         => $this->money($row->revenue_lost),
        ]);
    }

    /**
     * Per-code performance: usage count, revenue saved, and gateway breakdown.
     *
     * Returns a Collection with one entry per code. Each entry includes a
     * nested gateway_breakdown showing which gateways the code was redeemed on.
     *
     * ```php
     * [
     *   [
     *     'code'              => 'LAUNCH20',
     *     'type'              => 'percentage',
     *     'value'             => 20.0,
     *     'total_uses'        => 28,
     *     'revenue_lost'      => 840.00,
     *     'gateway_breakdown' => [
     *       ['gateway' => 'fanbasis', 'uses' => 20],
     *       ['gateway' => 'match2pay', 'uses' => 8],
     *     ],
     *   ],
     * ]
     * ```
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function perCodePerformance(): Collection
    {
        $paymentsTable = config('lp_payments.tables.payments', 'lp_payments');
        $codesTable    = config('lp_payments.tables.discount_codes', 'lp_discount_codes');

        // Summary per code
        $codes = $this->paidQuery()
            ->join("{$codesTable} as dc", 'dc.id', '=', "{$paymentsTable}.discount_code_id")
            ->whereNotNull("{$paymentsTable}.discount_code_id")
            ->selectRaw("
                dc.id as code_id,
                dc.code,
                dc.type,
                dc.value,
                COUNT(*) as total_uses,
                COALESCE(SUM({$paymentsTable}.discount_amount), 0) as revenue_lost
            ")
            ->groupBy('dc.id', 'dc.code', 'dc.type', 'dc.value')
            ->orderByDesc('total_uses')
            ->get();

        // Gateway breakdown per code
        $gatewayRows = $this->paidQuery()
            ->join("{$codesTable} as dc", 'dc.id', '=', "{$paymentsTable}.discount_code_id")
            ->whereNotNull("{$paymentsTable}.discount_code_id")
            ->selectRaw("dc.id as code_id, {$paymentsTable}.gateway, COUNT(*) as uses")
            ->groupBy('dc.id', "{$paymentsTable}.gateway")
            ->get()
            ->groupBy('code_id');

        return $codes->map(function ($row) use ($gatewayRows) {
            $breakdown = ($gatewayRows->get($row->code_id) ?? collect())
                ->map(fn ($g) => ['gateway' => $g->gateway, 'uses' => (int) $g->uses])
                ->values();

            return [
                'code'              => $row->code,
                'type'             => $row->type,
                'value'            => (float) $row->value,
                'total_uses'       => (int) $row->total_uses,
                'revenue_lost'     => $this->money($row->revenue_lost),
                'gateway_breakdown'=> $breakdown,
            ];
        });
    }

    /**
     * Discount code expiry analysis.
     *
     * Returns three groups:
     * - `expiring_soon` — codes expiring within the next 7 days
     * - `recently_expired` — codes that expired in the last 30 days
     * - `no_expiry` — active codes with no expiry date set
     *
     * @param  int  $soonDays  Days threshold for "expiring soon" (default 7).
     *
     * @return array<string, Collection>
     */
    public function expiryAnalysis(int $soonDays = 7): array
    {
        $table = config('lp_payments.tables.discount_codes', 'lp_discount_codes');

        $base = DB::table($table)->whereNull('deleted_at')->where('active', true);

        $expiringSoon = (clone $base)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays($soonDays))
            ->select(['id', 'code', 'type', 'value', 'times_used', 'max_total_uses', 'expires_at'])
            ->orderBy('expires_at')
            ->get()
            ->map(fn ($row) => (array) $row);

        $recentlyExpired = (clone $base)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->where('expires_at', '>=', now()->subDays(30))
            ->select(['id', 'code', 'type', 'value', 'times_used', 'max_total_uses', 'expires_at'])
            ->orderByDesc('expires_at')
            ->get()
            ->map(fn ($row) => (array) $row);

        $noExpiry = (clone $base)
            ->whereNull('expires_at')
            ->select(['id', 'code', 'type', 'value', 'times_used', 'max_total_uses'])
            ->orderByDesc('times_used')
            ->get()
            ->map(fn ($row) => (array) $row);

        return [
            'expiring_soon'    => $expiringSoon,
            'recently_expired' => $recentlyExpired,
            'no_expiry'        => $noExpiry,
        ];
    }

    /**
     * Compare conversion and payment behaviour: discounted vs full-price payments.
     *
     * ```php
     * [
     *   'with_discount' => [
     *     'payments'    => 42,
     *     'revenue'     => 1260.00,
     *     'aov'         => 30.00,
     *   ],
     *   'without_discount' => [
     *     'payments'    => 280,
     *     'revenue'     => 14000.00,
     *     'aov'         => 50.00,
     *   ],
     * ]
     * ```
     *
     * @return array<string, array<string, float|int>>
     */
    public function discountedVsFull(): array
    {
        $rows = $this->paidQuery()
            ->selectRaw('
                CASE WHEN discount_code_id IS NOT NULL THEN 1 ELSE 0 END as has_discount,
                COUNT(*) as payments,
                COALESCE(SUM(amount), 0) as revenue
            ')
            ->groupByRaw('CASE WHEN discount_code_id IS NOT NULL THEN 1 ELSE 0 END')
            ->get()
            ->keyBy('has_discount');

        $with    = $rows->get(1) ?? (object) ['payments' => 0, 'revenue' => 0];
        $without = $rows->get(0) ?? (object) ['payments' => 0, 'revenue' => 0];

        return [
            'with_discount' => [
                'payments' => (int) $with->payments,
                'revenue'  => $this->money($with->revenue),
                'aov'      => (int) $with->payments > 0
                    ? $this->money($with->revenue / $with->payments) : 0.0,
            ],
            'without_discount' => [
                'payments' => (int) $without->payments,
                'revenue'  => $this->money($without->revenue),
                'aov'      => (int) $without->payments > 0
                    ? $this->money($without->revenue / $without->payments) : 0.0,
            ],
        ];
    }
}
