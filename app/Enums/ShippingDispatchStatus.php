<?php

declare(strict_types=1);

namespace App\Enums;

enum ShippingDispatchStatus: string
{
    case NotApplicable = 'not_applicable';
    case PickingPending = 'picking_pending';
    case Picked = 'picked';
    case Packed = 'packed';
    case HandedToCourier = 'handed_to_courier';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::NotApplicable => 'N/A',
            self::PickingPending => 'Pending Pick',
            self::Picked => 'Picked',
            self::Packed => 'Packed',
            self::HandedToCourier => 'Handed to Courier',
            self::InTransit => 'In Transit',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
        };
    }
}
