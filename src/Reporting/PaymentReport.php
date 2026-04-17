<?php

namespace Subtain\LaravelPayments\Reporting;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Subtain\LaravelPayments\Enums\PaymentStatus;

/**
 * Abstract base class for all payment report classes.
 *
 * Provides shared query helpers that every report uses:
 *   - baseQuery()       — starts from lp_payments with all active filters applied
 *   - logsBaseQuery()   — starts from lp_payment_logs with gateway/date filters applied
 *   - periodExpression()— converts the groupByPeriod setting to a SQL expression
 *
 * No business logic lives here — only query building primitives.
 * Each concrete report class defines its own public methods that
 * return structured arrays or Collection objects for the caller.
 *
 * ## Isolation guarantee
 *
 * This class and all classes in the Reporting namespace are READ-ONLY.
 * They never write to any table and never interact with gateway code.
 * They are completely decoupled from the payment processing pipeline.
 */
abstract class PaymentReport
{
    public function __construct(
        protected ReportingFilters $filters,
    ) {}

    // ── Base Query Builders ────────────────────────────────────────────────

    /**
     * Return a base query builder on the payments table with all active filters applied.
     *
     * Every concrete report starts from this builder and adds its own
     * SELECT / GROUP BY / aggregate clauses on top.
     */
    protected function baseQuery(): Builder
    {
        $table = config('lp_payments.tables.payments', 'lp_payments');

        $query = DB::table($table);

        // Date range
        if ($this->filters->from !== null) {
            $query->where('created_at', '>=', $this->filters->from);
        }

        if ($this->filters->to !== null) {
            $query->where('created_at', '<=', $this->filters->to);
        }

        // Gateway filter
        if (! empty($this->filters->gateways)) {
            $query->whereIn('gateway', $this->filters->gateways);
        }

        // Currency filter
        if (! empty($this->filters->currencies)) {
            $query->whereIn('currency', $this->filters->currencies);
        }

        // Status filter
        if (! empty($this->filters->statuses)) {
            $query->whereIn('status', $this->filters->statuses);
        }

        // Sandbox filter
        // true  = exclude sandbox (real only)
        // false = include both
        // null  = sandbox only
        if ($this->filters->excludeSandbox === true) {
            $query->where('is_sandbox', false);
        } elseif ($this->filters->excludeSandbox === null) {
            $query->where('is_sandbox', true);
        }
        // false = no sandbox filter — both included

        // Payable type filter
        if (! empty($this->filters->payableTypes)) {
            $query->whereIn('payable_type', $this->filters->payableTypes);
        }

        // User filter
        if ($this->filters->userId !== null) {
            $query->where('user_id', $this->filters->userId);
        }

        // Customer email filter
        if ($this->filters->customerEmail !== null) {
            $query->where('customer_email', $this->filters->customerEmail);
        }

        return $query;
    }

    /**
     * Return a base query on the payments table scoped only to paid payments.
     *
     * Most revenue-related sub-queries start from here.
     */
    protected function paidQuery(): Builder
    {
        return $this->baseQuery()->where('status', PaymentStatus::PAID->value);
    }

    /**
     * Return a base query builder on the payment_logs table.
     *
     * Applies gateway and date filters when set.
     */
    protected function logsBaseQuery(): Builder
    {
        $table = config('lp_payments.tables.payment_logs', 'lp_payment_logs');

        $query = DB::table($table);

        if ($this->filters->from !== null) {
            $query->where('created_at', '>=', $this->filters->from);
        }

        if ($this->filters->to !== null) {
            $query->where('created_at', '<=', $this->filters->to);
        }

        if (! empty($this->filters->gateways)) {
            $query->whereIn('gateway', $this->filters->gateways);
        }

        return $query;
    }

    // ── SQL Helpers ────────────────────────────────────────────────────────

    /**
     * Return the DB-appropriate raw SQL string for grouping by the configured period.
     *
     * Returns a plain SQL string (not a DB::raw() expression) so it can be used
     * directly inside selectRaw(), groupByRaw(), and orderByRaw() calls.
     *
     * Supports: day, week, month, quarter, year.
     * Compatible with MySQL/MariaDB, PostgreSQL, and SQLite.
     */
    protected function periodExpression(string $column = 'created_at'): string
    {
        $driver = DB::getDriverName();

        return $this->dateTrunc($this->filters->groupByPeriod, $column, $driver);
    }

    /**
     * Generate a DB-driver-aware date truncation SQL string.
     */
    private function dateTrunc(string $period, string $column, string $driver): string
    {
        if ($driver === 'sqlite') {
            return match ($period) {
                'day'     => "strftime('%Y-%m-%d', {$column})",
                'week'    => "strftime('%Y-W%W', {$column})",
                'month'   => "strftime('%Y-%m', {$column})",
                'quarter' => "strftime('%Y', {$column}) || '-Q' || ((CAST(strftime('%m', {$column}) AS INTEGER) - 1) / 3 + 1)",
                'year'    => "strftime('%Y', {$column})",
                default   => "strftime('%Y-%m', {$column})",
            };
        }

        if ($driver === 'pgsql') {
            $trunc = match ($period) {
                'day'     => 'day',
                'week'    => 'week',
                'month'   => 'month',
                'quarter' => 'quarter',
                'year'    => 'year',
                default   => 'month',
            };

            return "DATE_TRUNC('{$trunc}', {$column})::date";
        }

        // MySQL / MariaDB (default)
        return match ($period) {
            'day'     => "DATE_FORMAT({$column}, '%Y-%m-%d')",
            'week'    => "DATE_FORMAT({$column}, '%Y-W%u')",
            'month'   => "DATE_FORMAT({$column}, '%Y-%m')",
            'quarter' => "CONCAT(YEAR({$column}), '-Q', QUARTER({$column}))",
            'year'    => "YEAR({$column})",
            default   => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }

    /**
     * Round a float to a consistent number of decimal places for money values.
     */
    protected function money(float|int|null $value, int $decimals = 2): float
    {
        return round((float) $value, $decimals);
    }

    /**
     * Calculate a percentage safely (guards against divide-by-zero).
     */
    protected function percentage(int|float $numerator, int|float $denominator, int $decimals = 2): float
    {
        if ($denominator == 0) {
            return 0.0;
        }

        return round(($numerator / $denominator) * 100, $decimals);
    }
}
