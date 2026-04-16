<?php

namespace Subtain\LaravelPayments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentLog extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'payload'    => 'array',
        'headers'    => 'array',
        'metadata'   => 'array',
        'is_sandbox' => 'boolean',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('lp_payments.tables.payment_logs', 'lp_payment_logs'));
    }

    /**
     * The payment this log belongs to.
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Create a log entry for a webhook or internal payment event.
     *
     * @param  bool  $isSandbox  Whether this log entry belongs to a sandboxed (simulated) payment.
     */
    public static function logWebhook(
        ?int $paymentId,
        string $gateway,
        string $event,
        array $payload,
        array $headers = [],
        ?string $status = null,
        bool $isSandbox = false,
    ): self {
        return static::create([
            'payment_id' => $paymentId,
            'gateway'    => $gateway,
            'event'      => $event,
            'status'     => $status,
            'payload'    => $payload,
            'headers'    => $headers,
            'is_sandbox' => $isSandbox,
        ]);
    }
}
