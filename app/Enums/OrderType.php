<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderType: string
{
    case DineIn = 'dine_in';
    case Takeaway = 'takeaway';
    case Delivery = 'delivery';
    case SelfOrder = 'self_order';

    public function label(): string
    {
        return match ($this) {
            self::DineIn => 'Dine-In',
            self::Takeaway => 'Takeaway',
            self::Delivery => 'Delivery',
            self::SelfOrder => 'Self Order',
        };
    }

    public function badgeType(): string
    {
        return match ($this) {
            self::DineIn => 'info',
            self::Takeaway => 'secondary',
            self::Delivery => 'warning',
            self::SelfOrder => 'success',
        };
    }

    /**
     * Coerce loose/legacy string inputs to a canonical OrderType.
     * Maps legacy 'take_away' to Takeaway.
     */
    public static function coerce(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        if ($value === 'take_away') {
            return self::Takeaway;
        }

        return self::tryFrom($value);
    }
}

