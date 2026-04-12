<?php

namespace Subtain\LaravelPayments\Exceptions;

use RuntimeException;

/**
 * Thrown when a payment gateway returns an error.
 *
 * Contains the gateway name and raw response for debugging.
 */
class PaymentException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $gateway = '',
        public readonly array $raw = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create from a gateway HTTP error response.
     */
    public static function fromResponse(string $gateway, string $body, int $statusCode = 0): static
    {
        return new static(
            message: "Payment gateway [{$gateway}] returned an error: {$body}",
            gateway: $gateway,
            raw: ['body' => $body, 'status_code' => $statusCode],
            code: $statusCode,
        );
    }
}
