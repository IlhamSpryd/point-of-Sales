<?php

declare(strict_types=1);

namespace App\Enums;

enum PoInvoicingStatusEnum: string
{
    case NotInvoiced = 'not_invoiced';
    case PartiallyInvoiced = 'partially_invoiced';
    case FullyInvoiced = 'fully_invoiced';

    public function label(): string
    {
        return match ($this) {
            self::NotInvoiced => 'Belum Ditagih',
            self::PartiallyInvoiced => 'Ditagih Sebagian',
            self::FullyInvoiced => 'Ditagih Penuh',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::NotInvoiced => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
            self::PartiallyInvoiced => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
            self::FullyInvoiced => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
        };
    }
}
