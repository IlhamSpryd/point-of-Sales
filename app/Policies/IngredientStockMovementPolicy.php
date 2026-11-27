<?php

declare(strict_types=1);

namespace App\Policies;

use App\Modules\Inventory\Models\IngredientStockMovement;
use App\Modules\Organization\Models\User;

class IngredientStockMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('inventory.view')
            || $user->hasPermission('ingredients.view')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Inventory', 'Admin'], true)
            || (bool) ($user->role->is_admin ?? false);
    }

    public function view(User $user, IngredientStockMovement $movement): bool
    {
        return (int) $user->tenant_id === (int) $movement->tenant_id
            && $this->viewAny($user);
    }
}

