<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderPaymentStatus: string
{
    case Unpaid = 'unpaid';
    case AwaitingPayment = 'awaiting_payment';
    case Paid = 'paid';
    case PartiallyRefunded = 'partially_refunded';
    case FullyRefunded = 'fully_refunded';
    case PaymentFailed = 'payment_failed';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::AwaitingPayment => 'Awaiting Payment',
            self::Paid => 'Paid',
            self::PartiallyRefunded => 'Partially Refunded',
            self::FullyRefunded => 'Fully Refunded',
            self::PaymentFailed => 'Payment Failed',
        };
    }

    public function badgeType(): string
    {
        return match ($this) {
            self::Unpaid => 'secondary',
            self::AwaitingPayment => 'warning',
            self::Paid => 'success',
            self::PartiallyRefunded, self::FullyRefunded => 'info',
            self::PaymentFailed => 'danger',
        };
    }
}
