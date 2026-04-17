<?php

namespace Subtain\LaravelPayments\Reporting\Reports;

use Illuminate\Support\Collection;
use Subtain\LaravelPayments\Enums\PaymentStatus;
use Subtain\LaravelPayments\Reporting\PaymentReport;

/**
 * Customer and user analytics.
 *
 * Answers questions like:
 *   - Who are our most valuable customers?
 *   - How many customers have bought more than once?
 *   - Which gateways do our customers prefer?
 *   - Where are our customers located?
 *
 * ## Usage
 *
 * ```php
 * $report = PaymentReports::customer(
 *     ReportingFilters::make()->thisYear()->excludeSandbox()
 * );
 *
 * $report->repeatBuyers();            // customers with 2+ successful payments
 * $report->lifetimeValue();           // total paid per customer (LTV)
 * $report->retentionSignals();        // first vs latest payment date per customer
 * $report->gatewayPreference();       // most-used gateway per customer
 * $report->newVsReturning();          // new (first payment in range) vs returning
 * $report->topCustomers();            // top N customers by LTV
 * ```
 */
class CustomerReport extends PaymentReport
{
    /**
     * Customers who have made more than one successful payment.
     *
     * Returns a Collection ordered by payment count descending:
     *
     * ```php
     * [
     *   [
     *     'customer_email'  => 'user@example.com',
     *     'successful_payments' => 4,
     *     'total_spent'     => 800.00,
     *     'first_payment'   => '2025-01-15 10:30:00',
     *     'last_payment'    => '2025-11-20 14:22:00',
     *   ],
     * ]
     * ```
     *
     * @param  int  $minimumPayments  Only return customers with at least this many paid payments (default 2).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function repeatBuyers(int $minimumPayments = 2): Collection
    {
        $limit = $this->filters->limit ?? 100;

        $rows = $this->paidQuery()
            ->whereNotNull('customer_email')
            ->selectRaw('
                customer_email,
                COUNT(*) as successful_payments,
                COALESCE(SUM(amount), 0) as total_spent,
                MIN(paid_at) as first_payment,
                MAX(paid_at) as last_payment
            ')
            ->groupBy('customer_email')
            ->havingRaw('COUNT(*) >= ?', [$minimumPayments])
            ->orderByDesc('successful_payments')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'customer_email'      => $row->customer_email,
            'successful_payments' => (int) $row->successful_payments,
            'total_spent'         => $this->money($row->total_spent),
            'first_payment'       => $row->first_payment,
            'last_payment'        => $row->last_payment,
        ]);
    }

    /**
     * Lifetime value (LTV) per customer — total amount paid.
     *
     * Returns a Collection ordered by total spent descending:
     *
     * ```php
     * [
     *   ['customer_email' => 'vip@example.com', 'total_spent' => 2400.00, 'payments' => 8],
     *   ['customer_email' => 'user@example.com','total_spent' => 300.00,  'payments' => 1],
     * ]
     * ```
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function lifetimeValue(): Collection
    {
        $limit = $this->filters->limit ?? 100;

        $rows = $this->paidQuery()
            ->whereNotNull('customer_email')
            ->selectRaw('
                customer_email,
                user_id,
                COUNT(*) as payments,
                COALESCE(SUM(amount), 0) as total_spent,
                MIN(created_at) as first_seen,
                MAX(created_at) as last_seen
            ')
            ->groupBy('customer_email', 'user_id')
            ->orderByDesc('total_spent')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'customer_email' => $row->customer_email,
            'user_id'        => $row->user_id,
            'payments'       => (int) $row->payments,
            'total_spent'    => $this->money($row->total_spent),
            'first_seen'     => $row->first_seen,
            'last_seen'      => $row->last_seen,
        ]);
    }

    /**
     * Customer retention signals — first vs latest payment date per customer.
     *
     * Returns customers who have paid at least twice, with their first and last
     * payment dates and the number of days between them (tenure).
     *
     * ```php
     * [
     *   [
     *     'customer_email' => 'user@example.com',
     *     'first_payment'  => '2025-01-10',
     *     'last_payment'   => '2025-11-20',
     *     'tenure_days'    => 314,
     *     'payments'       => 5,
     *   ],
     * ]
     * ```
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function retentionSignals(): Collection
    {
        $limit  = $this->filters->limit ?? 100;
        $driver = \Illuminate\Support\Facades\DB::getDriverName();

        $tenureExpr = match ($driver) {
            'sqlite' => 'CAST((julianday(MAX(paid_at)) - julianday(MIN(paid_at))) AS INTEGER)',
            'pgsql'  => "EXTRACT(DAY FROM (MAX(paid_at) - MIN(paid_at)))::integer",
            default  => 'DATEDIFF(MAX(paid_at), MIN(paid_at))',
        };

        $rows = $this->paidQuery()
            ->whereNotNull('customer_email')
            ->whereNotNull('paid_at')
            ->selectRaw("
                customer_email,
                COUNT(*) as payments,
                MIN(paid_at) as first_payment,
                MAX(paid_at) as last_payment,
                {$tenureExpr} as tenure_days
            ")
            ->groupBy('customer_email')
            ->havingRaw('COUNT(*) >= 2')
            ->orderByDesc('tenure_days')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'customer_email' => $row->customer_email,
            'payments'       => (int) $row->payments,
            'first_payment'  => $row->first_payment,
            'last_payment'   => $row->last_payment,
            'tenure_days'    => (int) ($row->tenure_days ?? 0),
        ]);
    }

    /**
     * Preferred gateway per customer — which gateway each customer uses most.
     *
     * Returns a Collection ordered by total payments descending:
     *
     * ```php
     * [
     *   ['customer_email' => 'user@example.com', 'preferred_gateway' => 'fanbasis', 'uses' => 3],
     * ]
     * ```
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function gatewayPreference(): Collection
    {
        $limit = $this->filters->limit ?? 100;

        // Single-pass aggregation: for each customer, find the gateway with the
        // highest COUNT. We use MAX(uses) inside a derived table so MySQL can
        // use the (customer_email, status) composite index and avoid a filesort.
        // This replaces the previous double-nested subquery approach.
        $rows = $this->paidQuery()
            ->whereNotNull('customer_email')
            ->selectRaw('
                customer_email,
                gateway as preferred_gateway,
                COUNT(*) as uses
            ')
            ->groupBy('customer_email', 'gateway')
            ->orderByDesc('uses')
            ->limit($limit)
            ->get();

        // Collapse to one row per customer — the first row per email is the highest-use gateway
        // because the result set is already ordered by uses DESC.
        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            if (isset($seen[$row->customer_email])) {
                continue;
            }
            $seen[$row->customer_email] = true;
            $result[] = [
                'customer_email'    => $row->customer_email,
                'preferred_gateway' => $row->preferred_gateway,
                'uses'              => (int) $row->uses,
            ];
        }

        return collect($result);
    }

    /**
     * New vs returning customers within the filter period.
     *
     * - New: the customer's first-ever payment is within the filter date range.
     * - Returning: the customer had a payment before the filter start date AND also paid within the range.
     *
     * ```php
     * [
     *   'new_customers'       => 45,
     *   'returning_customers' => 12,
     *   'new_revenue'         => 6750.00,
     *   'returning_revenue'   => 3600.00,
     * ]
     * ```
     *
     * @return array<string, int|float>
     */
    public function newVsReturning(): array
    {
        $paymentsTable = config('lp_payments.tables.payments', 'lp_payments');

        // Single query: for every customer who paid in the window, determine whether
        // they had ANY paid payment before the window start date.
        //
        // A customer is "returning" when MIN(created_at) across ALL their paid
        // payments is earlier than the window start — meaning their first-ever
        // payment pre-dates the report window.
        //
        // This collapses the previous 4-query approach (pluck emails, whereIn,
        // PHP diff, two more sums) into 2 aggregate queries.

        $fromDate = $this->filters->from;

        // Build the "in window" subfilter as the outer boundary
        $inWindowQuery = $this->paidQuery()->whereNotNull('customer_email');

        if ($inWindowQuery->count() === 0) {
            return [
                'new_customers'       => 0,
                'returning_customers' => 0,
                'new_revenue'         => 0.0,
                'returning_revenue'   => 0.0,
            ];
        }

        $rows = (clone $inWindowQuery)
            ->from($paymentsTable . ' as w')
            ->selectRaw('
                w.customer_email,
                SUM(w.amount) as revenue,
                COUNT(*) as payments
            ')
            ->groupBy('w.customer_email')
            ->get();

        // If no from-date is set, everyone is "new" (no concept of "before the window")
        if ($fromDate === null) {
            return [
                'new_customers'       => $rows->count(),
                'returning_customers' => 0,
                'new_revenue'         => $this->money($rows->sum('revenue')),
                'returning_revenue'   => 0.0,
            ];
        }

        // Determine first-ever paid date per customer (single query, no PHP loops)
        $emails = $rows->pluck('customer_email');
        $firstPayments = \Illuminate\Support\Facades\DB::table($paymentsTable)
            ->where('status', PaymentStatus::PAID->value)
            ->whereIn('customer_email', $emails)
            ->selectRaw('customer_email, MIN(created_at) as first_ever_paid_at')
            ->groupBy('customer_email')
            ->get()
            ->keyBy('customer_email');

        $newCustomers = 0;
        $returningCustomers = 0;
        $newRevenue = 0.0;
        $returningRevenue = 0.0;

        foreach ($rows as $row) {
            $firstPaid = $firstPayments->get($row->customer_email);
            $isReturning = $firstPaid && $firstPaid->first_ever_paid_at < $fromDate->toDateTimeString();

            if ($isReturning) {
                $returningCustomers++;
                $returningRevenue += (float) $row->revenue;
            } else {
                $newCustomers++;
                $newRevenue += (float) $row->revenue;
            }
        }

        return [
            'new_customers'       => $newCustomers,
            'returning_customers' => $returningCustomers,
            'new_revenue'         => $this->money($newRevenue),
            'returning_revenue'   => $this->money($returningRevenue),
        ];
    }

    /**
     * Top N customers by lifetime value.
     *
     * Shorthand for lifetimeValue() with a forced limit. Scoped to paid payments only.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function topCustomers(int $n = 10): Collection
    {
        // Push LIMIT to the DB — do not run lifetimeValue() and discard results in PHP.
        $rows = $this->paidQuery()
            ->whereNotNull('customer_email')
            ->selectRaw('
                customer_email,
                user_id,
                COUNT(*) as payments,
                COALESCE(SUM(amount), 0) as total_spent,
                MIN(created_at) as first_seen,
                MAX(created_at) as last_seen
            ')
            ->groupBy('customer_email', 'user_id')
            ->orderByDesc('total_spent')
            ->limit($n)
            ->get();

        return $rows->map(fn ($row) => [
            'customer_email' => $row->customer_email,
            'user_id'        => $row->user_id,
            'payments'       => (int) $row->payments,
            'total_spent'    => $this->money($row->total_spent),
            'first_seen'     => $row->first_seen,
            'last_seen'      => $row->last_seen,
        ]);
    }
}
