<?php

declare(strict_types=1);

namespace App\Platform\Exceptions;

use DomainException;

class IllegalStateTransitionException extends DomainException
{
    public function __construct(
        string $entity,
        string $fromState,
        string $toState,
        string $reason = ''
    ) {
        $msg = sprintf(
            'Illegal state transition for %s: cannot transition from "%s" to "%s".%s',
            $entity,
            $fromState,
            $toState,
            $reason !== '' ? " Reason: {$reason}" : ''
        );

        parent::__construct($msg);
    }
}
