<?php

declare(strict_types=1);

namespace App\Modules\Sales\Enums;

enum QuotationStatusEnum: string
{
    case DRAFT = 'DRAFT';
    case PENDING_APPROVAL = 'PENDING_APPROVAL';
    case APPROVED = 'APPROVED';
    case SENT = 'SENT';
    case ACCEPTED = 'ACCEPTED';
    case CONVERTED = 'CONVERTED';
    case REJECTED = 'REJECTED';
    case EXPIRED = 'EXPIRED';
    case CANCELLED = 'CANCELLED';
    case SUPERSEDED = 'SUPERSEDED';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::CONVERTED,
            self::REJECTED,
            self::EXPIRED,
            self::CANCELLED,
            self::SUPERSEDED,
        ], true);
    }

    public function canBeEdited(): bool
    {
        return in_array($this, [
            self::DRAFT,
            self::PENDING_APPROVAL,
        ], true);
    }

    public function canBeConverted(): bool
    {
        return $this === self::ACCEPTED;
    }
}
