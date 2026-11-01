<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Pricing\Models\PromotionCampaign;

class PromotionCampaignPolicy
{
    private function canManage(User $user): bool
    {
        return in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
            || (bool) ($user->role->is_admin ?? false)
            || $user->hasPermission('pricing.promotions.manage');
    }

    public function viewAny(User $user): bool
    {
        return $this->canManage($user) || $user->hasPermission('pricing.promotions.view');
    }

    public function view(User $user, PromotionCampaign $campaign): bool
    {
        if ((int) $user->tenant_id !== (int) $campaign->tenant_id) {
            return false;
        }

        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, PromotionCampaign $campaign): bool
    {
        if ((int) $user->tenant_id !== (int) $campaign->tenant_id) {
            return false;
        }

        return $this->canManage($user);
    }

    public function delete(User $user, PromotionCampaign $campaign): bool
    {
        if ((int) $user->tenant_id !== (int) $campaign->tenant_id) {
            return false;
        }

        return $this->canManage($user);
    }

    public function approve(User $user, PromotionCampaign $campaign): bool
    {
        if ((int) $user->tenant_id !== (int) $campaign->tenant_id) {
            return false;
        }

        return in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
            || (bool) ($user->role->is_admin ?? false)
            || $user->hasPermission('pricing.promotions.approve');
    }
}
