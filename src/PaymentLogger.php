<?php

namespace Subtain\LaravelPayments;

use Illuminate\Support\Facades\Log;
use Subtain\LaravelPayments\KeyFingerprint;

/**
 * Central logging hub for the laravel-payments package.
 *
 * Every log call from every gateway, client, service, and controller in this
 * package goes through this class. This gives the developer full control over
 * where payment logs are sent, at what level, and what sensitive data is
 * redacted — all from a single config block.
 *
 * ## How it works
 *
 * 1. Each call carries a `$gateway` name (e.g. "match2pay", "fanbasis") and a
 *    `$category` (e.g. "checkout", "webhook", "api", "signature").
 * 2. The logger looks up the channel to write to in this priority order:
 *      a. Per-gateway channel:  config('lp_payments.logging.channels.match2pay')
 *      b. Default channel:      config('lp_payments.logging.channels.default')
 *      c. App default:          config('logging.default')  (Laravel's own default)
 * 3. Calls below the configured minimum level are silently dropped.
 * 4. Context keys listed in config('lp_payments.logging.redact') are masked.
 *
 * ## Usage inside the package
 *
 * ```php
 * PaymentLogger::info('checkout.initiated', ['invoice_id' => $id], gateway: 'match2pay');
 * PaymentLogger::error('checkout.failed',   ['error' => $msg],     gateway: 'rebornpay');
 * PaymentLogger::debug('api.request',       ['payload' => $data],  gateway: 'fanbasis', category: 'api');
 * ```
 *
 * ## Developer configuration
 *
 * See config/lp_payments.php under the `logging` key, or refer to docs/logging.md.
 */
class PaymentLogger
{
    /**
     * Ordered list of log levels from least to most severe.
     * Used to check whether a call meets the minimum configured level.
     */
    private const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /**
     * Log at DEBUG level.
     *
     * Use for: raw API request/response payloads, internal state that is
     * too noisy for production but useful when debugging a gateway issue.
     *
     * @param  string  $event     Dot-notation event name, e.g. "api.request"
     * @param  array   $context   Key-value pairs to include in the log entry
     * @param  string|null  $gateway   Gateway name, e.g. "match2pay" (used for channel + level routing)
     * @param  string|null  $category  Optional sub-category, e.g. "api", "webhook", "checkout"
     */
    public static function debug(
        string $event,
        array $context = [],
        ?string $gateway = null,
        ?string $category = null,
    ): void {
        static::write('debug', $event, $context, $gateway, $category);
    }

    /**
     * Log at INFO level.
     *
     * Use for: successful checkout initiation, successful webhook parsed, status transitions.
     * These are the standard operational logs — enabled by default.
     */
    public static function info(
        string $event,
        array $context = [],
        ?string $gateway = null,
        ?string $category = null,
    ): void {
        static::write('info', $event, $context, $gateway, $category);
    }

    /**
     * Log at WARNING level.
     *
     * Use for: signature verification skipped (no secret configured), unexpected but recoverable
     * situations, deprecation notices.
     */
    public static function warning(
        string $event,
        array $context = [],
        ?string $gateway = null,
        ?string $category = null,
    ): void {
        static::write('warning', $event, $context, $gateway, $category);
    }

    /**
     * Log at ERROR level.
     *
     * Use for: gateway returned an unexpected response, signature verification failed,
     * HTTP errors, exceptions thrown during checkout or webhook processing.
     */
    public static function error(
        string $event,
        array $context = [],
        ?string $gateway = null,
        ?string $category = null,
    ): void {
        static::write('error', $event, $context, $gateway, $category);
    }

    /**
     * Log at CRITICAL level.
     *
     * Use for: unrecoverable errors that need immediate attention (e.g. double-charging,
     * data integrity failures, state machine violations that could not be handled).
     */
    public static function critical(
        string $event,
        array $context = [],
        ?string $gateway = null,
        ?string $category = null,
    ): void {
        static::write('critical', $event, $context, $gateway, $category);
    }

    /**
     * Core write method — all public methods delegate here.
     *
     * Responsibilities:
     *  1. Bail early if logging is disabled
     *  2. Check minimum level for the gateway
     *  3. Resolve the target log channel
     *  4. Redact sensitive context keys
     *  5. Build a structured message prefix and write
     *
     * @param  string  $level     PSR-3 log level
     * @param  string  $event     Dot-notation event name
     * @param  array   $context   Raw context (will be redacted before writing)
     * @param  string|null  $gateway   Gateway name for routing and filtering
     * @param  string|null  $category  Optional sub-category for richer filtering
     */
    public static function write(
        string $level,
        string $event,
        array $context = [],
        ?string $gateway = null,
        ?string $category = null,
    ): void {
        $config = config('lp_payments.logging', []);

        // 1. Global kill switch
        if (! ($config['enabled'] ?? true)) {
            return;
        }

        // 2. Minimum level check — per-gateway override first, then global, then 'debug'
        $minLevel = $config['levels'][$gateway] ?? $config['level'] ?? 'debug';
        if (! static::meetsMinimumLevel($level, $minLevel)) {
            return;
        }

        // 3. Resolve channel — per-gateway first, then default, then let Laravel decide
        $channels = $config['channels'] ?? [];
        $channel  = ($gateway !== null ? ($channels[$gateway] ?? null) : null)
                    ?? $channels['default']
                    ?? null;

        $logger = $channel !== null
            ? Log::channel($channel)
            : Log::getLogger();

        // 4. Redact sensitive keys from context
        $safeContext = static::redact($context, $config['redact'] ?? []);

        // 5. Append key fingerprints for the gateway so every log entry carries
        //    an audit trail of which API key was active at the time of the call.
        //    This is done AFTER redaction so raw keys never appear in logs.
        if ($gateway !== null) {
            $fingerprints = KeyFingerprint::forGateway($gateway);
            if ($fingerprints !== []) {
                $safeContext['_key_fingerprints'] = $fingerprints;
            }
        }

        // 6. Build prefix for easy log scanning and write
        $prefix  = static::buildPrefix($gateway, $category);
        $message = $prefix . $event;

        $logger->log($level, $message, $safeContext);
    }

    /**
     * Build a consistent log message prefix.
     *
     * Examples:
     *   "[payments:match2pay:api] "
     *   "[payments:fanbasis:webhook] "
     *   "[payments] "
     *
     * The prefix makes it trivial to grep or filter logs by gateway or category.
     *
     * @param  string|null  $gateway
     * @param  string|null  $category
     */
    private static function buildPrefix(?string $gateway, ?string $category): string
    {
        $parts = ['payments'];

        if ($gateway !== null && $gateway !== '') {
            $parts[] = $gateway;
        }

        if ($category !== null && $category !== '') {
            $parts[] = $category;
        }

        return '[' . implode(':', $parts) . '] ';
    }

    /**
     * Redact sensitive keys from the log context.
     *
     * Replaces the value of any key listed in $redactKeys with '[redacted]'.
     * Works recursively on nested arrays so deeply-nested secrets are also masked.
     *
     * The default redact list is defined in config('lp_payments.logging.redact') and
     * covers common credential field names. Developers may extend this list.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string>         $redactKeys
     * @return array<string, mixed>
     */
    public static function redact(array $context, array $redactKeys = []): array
    {
        if (empty($redactKeys)) {
            return $context;
        }

        $normalized = array_map('strtolower', $redactKeys);

        $result = [];
        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), $normalized, true)) {
                $result[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $result[$key] = static::redact($value, $redactKeys);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Check whether a given log level meets the configured minimum.
     *
     * Levels from lowest to highest: debug, info, notice, warning, error, critical, alert, emergency.
     * A call at level "info" with minLevel "warning" → false (dropped).
     * A call at level "error" with minLevel "warning" → true (written).
     *
     * Unknown levels are treated as meeting any minimum (fail open).
     *
     * @param  string  $level     The level of the current log call
     * @param  string  $minLevel  The minimum configured level
     */
    private static function meetsMinimumLevel(string $level, string $minLevel): bool
    {
        $levelIndex    = array_search(strtolower($level),    self::LEVELS, true);
        $minLevelIndex = array_search(strtolower($minLevel), self::LEVELS, true);

        // Unknown level — fail open (write it)
        if ($levelIndex === false || $minLevelIndex === false) {
            return true;
        }

        return $levelIndex >= $minLevelIndex;
    }
}
