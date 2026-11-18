<?php

declare(strict_types=1);

namespace App\Platform\Authorization;

final readonly class Ability
{
    public function __construct(
        public string $name,
        public string $module,
        public Scope $scope = Scope::BRANCH,
        public bool $requiresApproval = false,
        public string $description = ''
    ) {}
}
