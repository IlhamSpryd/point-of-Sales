<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class Modul8PermissionsSeeder extends Seeder
{
    /**
     * Seed canonical RBAC permissions for Modul 8 (Pricing, Promotion & Loyalty).
     */
    public function run(): void
    {
        $canonicalPermissions = [
            'pricing.pricelists.view' => 'Melihat master daftar harga',
            'pricing.pricelists.manage' => 'Membuat dan mengelola daftar harga',
            'pricing.promotions.view' => 'Melihat daftar kampanye promosi',
            'pricing.promotions.manage' => 'Membuat dan mengubah kampanye promosi',
            'pricing.promotions.approve' => 'Menyetujui dan mempublikasikan promosi',
            'pricing.vouchers.manage' => 'Mengelola kupon dan voucher belanja',
            'pricing.margin_simulation.execute' => 'Menjalankan simulasi marjin kotor promosi',
            'pos.price.open_entry' => 'Memasukkan open price di kasir',
            'pos.discount.manual_apply' => 'Mengajukan diskon manual di kasir',
            'pos.discount.manual_approve' => 'Menyetujui diskon manual supervisor',
            'loyalty.points.view' => 'Melihat saldo dan mutasi poin pelanggan',
            'loyalty.points.adjust' => 'Melakukan penyesuaian manual poin loyalitas',
            'loyalty.tiers.manage' => 'Mengelola tingkatan tier membership',
            'gift_cards.view' => 'Melihat saldo dan mutasi Gift Card',
            'gift_cards.manage' => 'Menerbitkan dan mengelola Gift Card',
            'gift_cards.redeem' => 'Menerima pembayaran menggunakan Gift Card',
            'store_credit.view' => 'Melihat saldo dan mutasi Store Credit',
            'store_credit.manage' => 'Menerbitkan dan mengelola saldo Store Credit',
        ];

        // Seed to all existing tenants' roles
        $tenants = Tenant::all();
        foreach ($tenants as $tenant) {
            $roles = Role::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get();

            foreach ($roles as $role) {
                $currentPerms = is_array($role->permissions) ? $role->permissions : [];

                if (in_array('*', $currentPerms, true)) {
                    continue; // Owner already has full wildcard access
                }

                $newPerms = match ($role->name) {
                    'Manager' => array_keys($canonicalPermissions),
                    'Supervisor' => [
                        'pricing.promotions.view',
                        'pos.price.open_entry',
                        'pos.discount.manual_approve',
                        'gift_cards.redeem',
                        'loyalty.points.view',
                    ],
                    'Kasir' => [
                        'pos.price.open_entry',
                        'pos.discount.manual_apply',
                        'gift_cards.redeem',
                        'loyalty.points.view',
                    ],
                    default => [],
                };

                if (!empty($newPerms)) {
                    $merged = array_values(array_unique(array_merge($currentPerms, $newPerms)));
                    $role->permissions = $merged;
                    $role->save();
                }
            }
        }
    }
}
