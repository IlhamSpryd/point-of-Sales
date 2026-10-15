<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Ingredient;
use App\Models\User;

/**
 * [D2-BE-004] Policy Ingredient: CRUD bahan baku + BOM + penyesuaian stok
 * manual, dengan kecocokan tenant_id & store_id.
 */
class IngredientPolicy
{
    private function canManage(User $user): bool
    {
        return in_array($user->role?->name, ['Owner', 'Manager', 'Inventory'], true);
    }

    private function sameTenant(User $user, Ingredient $ingredient): bool
    {
        return (int) $user->tenant_id === (int) $ingredient->tenant_id;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Ingredient $ingredient): bool
    {
        return $this->sameTenant($user, $ingredient);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Ingredient $ingredient): bool
    {
        return $this->canManage($user) && $this->sameTenant($user, $ingredient);
    }

    public function delete(User $user, Ingredient $ingredient): bool
    {
        return $this->canManage($user) && $this->sameTenant($user, $ingredient);
    }

    /** Menyesuaikan BOM/resep produk & modifier. */
    public function manageRecipe(User $user): bool
    {
        return in_array($user->role?->name, ['Owner', 'Manager', 'Inventory'], true);
    }

    /** Penyesuaian stok manual (stock adjustment / opname). */
    public function adjustStock(User $user, Ingredient $ingredient): bool
    {
        return $this->canManage($user) && $this->sameTenant($user, $ingredient);
    }
}
