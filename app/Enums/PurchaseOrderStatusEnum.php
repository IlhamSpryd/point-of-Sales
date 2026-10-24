<?php

declare(strict_types=1);

namespace App\Enums;

enum PurchaseOrderStatusEnum: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Released = 'released';
    case Amended = 'amended';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::PendingApproval => 'Menunggu Persetujuan',
            self::Approved => 'Disetujui',
            self::Rejected => 'Ditolak',
            self::Released => 'Diterbitkan',
            self::Amended => 'Diamandemen',
            self::Closed => 'Ditutup',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
            self::PendingApproval => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
            self::Approved => 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
            self::Rejected => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
            self::Released => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
            self::Amended => 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300',
            self::Closed => 'bg-slate-100 text-slate-800 dark:bg-slate-700 dark:text-slate-300',
            self::Cancelled => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Closed, self::Cancelled, self::Rejected], true);
    }
}
