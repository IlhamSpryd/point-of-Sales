<?php

declare(strict_types=1);

namespace App\Platform\Authorization;

enum Scope: string
{
    case TENANT = 'tenant';
    case COMPANY = 'company';
    case BRANCH = 'branch';
    case SELF = 'self';

    public function rank(): int
    {
        return match ($this) {
            self::TENANT => 4,
            self::COMPANY => 3,
            self::BRANCH => 2,
            self::SELF => 1,
        };
    }

    public function satisfies(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }
}
