<?php

declare(strict_types=1);

namespace App\Enums;

enum SupplierStatusEnum: string
{
    case Active = 'active';
    case OnHold = 'on_hold';
    case Inactive = 'inactive';
    case Archived = 'archived';

    public function isUsableForNewOrders(): bool
    {
        return $this === self::Active;
    }

    public function isArchived(): bool
    {
        return $this === self::Archived;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::OnHold => 'Ditahan (On Hold)',
            self::Inactive => 'Non-Aktif',
            self::Archived => 'Diarsipkan',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::OnHold => 'warning',
            self::Inactive => 'secondary',
            self::Archived => 'danger',
        };
    }
}
