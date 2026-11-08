<?php

declare(strict_types=1);

namespace App\Enums;

enum FulfillmentChannel: string
{
    case DineIn = 'on_premise_dine_in';
    case Takeaway = 'takeaway_counter';
    case Pickup = 'curbside_pickup';
    case ExpressDelivery = 'express_delivery';
    case ThirdPartyLogistics = 'third_party_logistics';

    public function label(): string
    {
        return match ($this) {
            self::DineIn => 'Dine-In',
            self::Takeaway => 'Takeaway',
            self::Pickup => 'Pickup',
            self::ExpressDelivery => 'Express Delivery',
            self::ThirdPartyLogistics => 'Courier (3PL)',
        };
    }
}
