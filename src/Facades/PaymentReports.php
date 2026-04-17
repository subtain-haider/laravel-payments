<?php

namespace Subtain\LaravelPayments\Facades;

use Illuminate\Support\Facades\Facade;
use Subtain\LaravelPayments\Reporting\Reports\ConversionReport;
use Subtain\LaravelPayments\Reporting\Reports\CustomerReport;
use Subtain\LaravelPayments\Reporting\Reports\DiscountReport;
use Subtain\LaravelPayments\Reporting\Reports\GatewayReport;
use Subtain\LaravelPayments\Reporting\Reports\PayableReport;
use Subtain\LaravelPayments\Reporting\Reports\RefundReport;
use Subtain\LaravelPayments\Reporting\Reports\RevenueReport;
use Subtain\LaravelPayments\Reporting\Reports\WebhookAuditReport;
use Subtain\LaravelPayments\Reporting\ReportingFilters;

/**
 * Facade for payment analytics and reporting.
 *
 * Each method returns a report object pre-configured with the given filters.
 * Call the report methods on the returned object to get the data.
 *
 * ## Quick Start
 *
 * ```php
 * use Subtain\LaravelPayments\Facades\PaymentReports;
 * use Subtain\LaravelPayments\Reporting\ReportingFilters;
 *
 * $filters = ReportingFilters::make()->thisYear()->excludeSandbox();
 *
 * PaymentReports::revenue($filters)->summary();
 * PaymentReports::revenue($filters)->byGateway();
 * PaymentReports::revenue($filters)->trend();
 * PaymentReports::conversion($filters)->conversionRate();
 * PaymentReports::gateway($filters)->webhookReliability();
 * PaymentReports::discount($filters)->topCodes();
 * PaymentReports::customer($filters)->lifetimeValue();
 * PaymentReports::refund($filters)->timeToRefund();
 * PaymentReports::webhookAudit($filters)->duplicateWebhooks();
 * PaymentReports::payable($filters)->byType();
 * ```
 *
 * @method static RevenueReport      revenue(?ReportingFilters $filters = null)
 * @method static ConversionReport   conversion(?ReportingFilters $filters = null)
 * @method static GatewayReport      gateway(?ReportingFilters $filters = null)
 * @method static DiscountReport     discount(?ReportingFilters $filters = null)
 * @method static CustomerReport     customer(?ReportingFilters $filters = null)
 * @method static RefundReport       refund(?ReportingFilters $filters = null)
 * @method static WebhookAuditReport webhookAudit(?ReportingFilters $filters = null)
 * @method static PayableReport      payable(?ReportingFilters $filters = null)
 *
 * @see \Subtain\LaravelPayments\Reporting\ReportingManager
 */
class PaymentReports extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'payment.reports';
    }
}
