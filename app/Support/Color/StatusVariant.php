<?php

declare(strict_types=1);

namespace App\Support\Color;

/**
 * The five visual meanings a status can carry across every ERP module.
 *
 * Deliberately small: a status is either good, needs attention, is bad,
 * is merely informational, or carries no judgement yet.
 */
enum StatusVariant: string
{
    case Success = 'success';
    case Warning = 'warning';
    case Destructive = 'destructive';
    case Info = 'info';
    case Neutral = 'neutral';

    /** Human-readable name matching the semantic token prefix. */
    public function label(): string
    {
        return ucfirst($this->value);
    }
}
