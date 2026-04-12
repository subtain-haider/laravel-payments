<?php

namespace Subtain\LaravelPayments\Enums;

enum DiscountType: string
{
    case PERCENTAGE = 'percentage';
    case FIXED = 'fixed';
}
