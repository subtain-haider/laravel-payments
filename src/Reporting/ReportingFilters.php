<?php

namespace Subtain\LaravelPayments\Reporting;

use Carbon\Carbon;

/**
 * Fluent filter builder for all payment reports.
 *
 * Every report class accepts a ReportingFilters instance and applies
 * only the filters that are relevant to that report type. Unused
 * filters are silently ignored, so you can build one filter object
 * and pass it to multiple report types.
 *
 * ## Usage
 *
 * ```php
 * $filters = ReportingFilters::make()
 *     ->from('2025-01-01')
 *     ->to('2025-12-31')
 *     ->gateways(['fanbasis', 'match2pay'])
 *     ->currencies(['USD'])
 *     ->excludeSandbox()
 *     ->groupBy('month');
 *
 * PaymentReports::revenue($filters)->summary();
 * PaymentReports::conversion($filters)->conversionRate();
 * ```
 */
class ReportingFilters
{
    /** @var Carbon|null Start of the reporting period (inclusive). */
    public ?Carbon $from = null;

    /** @var Carbon|null End of the reporting period (inclusive). */
    public ?Carbon $to = null;

    /** @var string[]|null Filter to specific gateways. Null means all gateways. */
    public ?array $gateways = null;

    /** @var string[]|null Filter to specific currencies. Null means all currencies. */
    public ?array $currencies = null;

    /** @var string[]|null Filter to specific payment statuses. Null means all statuses. */
    public ?array $statuses = null;

    /**
     * Whether to include sandbox payments.
     *
     * true  = real payments only (default for most report use-cases)
     * false = include both real and sandbox
     * null  = sandbox only
     */
    public bool|null $excludeSandbox = true;

    /**
     * Time period grouping for trend reports.
     *
     * Supported: 'day', 'week', 'month', 'quarter', 'year'
     */
    public string $groupByPeriod = 'month';

    /** @var string[]|null Filter to specific payable_type values (e.g. 'App\Models\Order'). */
    public ?array $payableTypes = null;

    /** @var int|null Limit number of rows returned (for top-N reports). */
    public ?int $limit = null;

    /** @var int|null Specific user_id to scope reports to a single user. */
    public ?int $userId = null;

    /** @var string|null Specific customer_email to scope reports to a single customer. */
    public ?string $customerEmail = null;

    // ── Factory ────────────────────────────────────────────────────────────

    /**
     * Create a new blank filter set.
     */
    public static function make(): static
    {
        return new static();
    }

    // ── Date Filters ───────────────────────────────────────────────────────

    /**
     * Set the start of the reporting window.
     *
     * @param  string|Carbon  $date
     */
    public function from(string|Carbon $date): static
    {
        $this->from = is_string($date) ? Carbon::parse($date)->startOfDay() : $date;

        return $this;
    }

    /**
     * Set the end of the reporting window.
     *
     * @param  string|Carbon  $date
     */
    public function to(string|Carbon $date): static
    {
        $this->to = is_string($date) ? Carbon::parse($date)->endOfDay() : $date;

        return $this;
    }

    /**
     * Scope to today only.
     */
    public function today(): static
    {
        $this->from = Carbon::today()->startOfDay();
        $this->to   = Carbon::today()->endOfDay();

        return $this;
    }

    /**
     * Scope to the current calendar month.
     */
    public function thisMonth(): static
    {
        $this->from = Carbon::now()->startOfMonth();
        $this->to   = Carbon::now()->endOfMonth();

        return $this;
    }

    /**
     * Scope to the previous calendar month.
     */
    public function lastMonth(): static
    {
        $this->from = Carbon::now()->subMonth()->startOfMonth();
        $this->to   = Carbon::now()->subMonth()->endOfMonth();

        return $this;
    }

    /**
     * Scope to the current calendar year.
     */
    public function thisYear(): static
    {
        $this->from = Carbon::now()->startOfYear();
        $this->to   = Carbon::now()->endOfYear();

        return $this;
    }

    /**
     * Scope to the last N days.
     */
    public function lastDays(int $days): static
    {
        $this->from = Carbon::now()->subDays($days)->startOfDay();
        $this->to   = Carbon::now()->endOfDay();

        return $this;
    }

    // ── Gateway / Currency Filters ─────────────────────────────────────────

    /**
     * Filter to one or more specific gateways.
     *
     * @param  string|string[]  $gateways  e.g. 'fanbasis' or ['fanbasis', 'match2pay']
     */
    public function gateways(string|array $gateways): static
    {
        $this->gateways = (array) $gateways;

        return $this;
    }

    /**
     * Filter to a single gateway.
     */
    public function gateway(string $gateway): static
    {
        return $this->gateways($gateway);
    }

    /**
     * Filter to one or more currencies.
     *
     * @param  string|string[]  $currencies  e.g. 'USD' or ['USD', 'EUR']
     */
    public function currencies(string|array $currencies): static
    {
        $this->currencies = (array) $currencies;

        return $this;
    }

    /**
     * Filter to a single currency.
     */
    public function currency(string $currency): static
    {
        return $this->currencies($currency);
    }

    // ── Status Filters ─────────────────────────────────────────────────────

    /**
     * Filter to one or more payment statuses.
     *
     * @param  string|string[]  $statuses  e.g. 'paid' or ['paid', 'refunded']
     */
    public function statuses(string|array $statuses): static
    {
        $this->statuses = (array) $statuses;

        return $this;
    }

    // ── Sandbox Filters ────────────────────────────────────────────────────

    /**
     * Exclude sandbox (simulated) payments — show only real payments.
     *
     * This is the default behaviour.
     */
    public function excludeSandbox(): static
    {
        $this->excludeSandbox = true;

        return $this;
    }

    /**
     * Include both real and sandbox payments in results.
     */
    public function includeSandbox(): static
    {
        $this->excludeSandbox = false;

        return $this;
    }

    /**
     * Show only sandbox (simulated) payments.
     */
    public function sandboxOnly(): static
    {
        $this->excludeSandbox = null;

        return $this;
    }

    // ── Grouping ───────────────────────────────────────────────────────────

    /**
     * Set the time period granularity for trend/grouped reports.
     *
     * @param  string  $period  One of: 'day', 'week', 'month', 'quarter', 'year'
     *
     * @throws \InvalidArgumentException if the period is not supported.
     */
    public function groupBy(string $period): static
    {
        $supported = ['day', 'week', 'month', 'quarter', 'year'];

        if (! in_array($period, $supported, true)) {
            throw new \InvalidArgumentException(
                "Unsupported groupBy period [{$period}]. Supported: " . implode(', ', $supported)
            );
        }

        $this->groupByPeriod = $period;

        return $this;
    }

    // ── Payable / Product Filters ──────────────────────────────────────────

    /**
     * Filter to payments for specific payable model types.
     *
     * @param  string|string[]  $types  e.g. 'App\Models\Order'
     */
    public function payableTypes(string|array $types): static
    {
        $this->payableTypes = (array) $types;

        return $this;
    }

    // ── User / Customer Filters ────────────────────────────────────────────

    /**
     * Scope to a specific user by their ID.
     */
    public function forUser(int $userId): static
    {
        $this->userId = $userId;

        return $this;
    }

    /**
     * Scope to a specific customer by email.
     */
    public function forEmail(string $email): static
    {
        $this->customerEmail = strtolower(trim($email));

        return $this;
    }

    // ── Pagination / Limits ────────────────────────────────────────────────

    /**
     * Limit the number of results returned (useful for top-N reports).
     */
    public function limit(int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    // ── Serialisation ──────────────────────────────────────────────────────

    /**
     * Export the current filter state as an array (useful for debugging/logging).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from'           => $this->from?->toDateTimeString(),
            'to'             => $this->to?->toDateTimeString(),
            'gateways'       => $this->gateways,
            'currencies'     => $this->currencies,
            'statuses'       => $this->statuses,
            'exclude_sandbox'=> $this->excludeSandbox,
            'group_by'       => $this->groupByPeriod,
            'payable_types'  => $this->payableTypes,
            'limit'          => $this->limit,
            'user_id'        => $this->userId,
            'customer_email' => $this->customerEmail,
        ];
    }
}
