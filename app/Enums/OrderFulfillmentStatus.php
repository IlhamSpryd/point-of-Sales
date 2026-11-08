<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderFulfillmentStatus: string
{
    case Unfulfilled = 'unfulfilled';
    case InPreparation = 'in_prep';
    case ReadyForPickup = 'ready';
    case OutForDelivery = 'out_for_delivery';
    case Fulfilled = 'fulfilled';
    case PartiallyReturned = 'partially_returned';
    case FullyReturned = 'fully_returned';

    public function label(): string
    {
        return match ($this) {
            self::Unfulfilled => 'Unfulfilled',
            self::InPreparation => 'In Preparation',
            self::ReadyForPickup => 'Ready for Pickup',
            self::OutForDelivery => 'Out for Delivery',
            self::Fulfilled => 'Fulfilled',
            self::PartiallyReturned => 'Partially Returned',
            self::FullyReturned => 'Fully Returned',
        };
    }

    public function badgeType(): string
    {
        return match ($this) {
            self::Unfulfilled => 'secondary',
            self::InPreparation => 'warning',
            self::ReadyForPickup, self::OutForDelivery => 'info',
            self::Fulfilled => 'success',
            self::PartiallyReturned, self::FullyReturned => 'danger',
        };
    }
}
