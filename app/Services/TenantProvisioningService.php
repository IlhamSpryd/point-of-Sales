<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * Domain 1 (XD-02 / DB-002) — kontrak KESIAPAN tenant baru.
 *
 * Sumber kebenaran perilaku (dibaca dari PRD, bukan diciptakan di sini):
 *  - "Provisioning, onboarding … Not defined [OPEN, OD-29]" — PRD §27
 *    menyerahkan bentuk provisioning ke pemilik produk, jadi tidak ada
 *    daftar langkah yang boleh diklaim sebagai "diminta PRD". Karena itu
 *    default di bawah bersifat MINIMAL dan eksplisit, dan setiap butir
 *    dicatat sebagai keputusan yang menunggu persetujuan (lihat laporan).
 *  - PRD §4 ACT-002: Owner memiliki "Whole tenant, all branches" — jadi role
 *    Owner per-tenant dengan permission penuh adalah syarat keras.
 *  - PRD §6 (FR-1047/1034): setiap penjualan punya kanal; kanal ONLINE
 *    "seeded". Kanal order masuk perlu akun atribusi karena
 *    `ProcessWebhookOrderJob` mencari user sistem kanal dan gagal
 *    (log + abort) bila akunnya tidak ada.
 *  - Self-order pelanggan memakai "kasir virtual" yang sama polanya:
 *    `SystemUserResolver::selfOrder()` abort 500 bila akun tidak ada.
 *
 * Kontrak yang ditegakkan sekarang:
 *  - Owner user + role Owner (permission ['*']): WAJIB
 *  - 8 role standar (RoleService::provisionDefaultRoles): WAJIB
 *  - Store pertama + store setting baseline: WAJIB
 *  - User sistem self-order & channel order: WAJIB
 *
 * Semua dibuat di dalam transaksi provisioning yang sama. Bila salah satu
 * gagal, seluruh transaksi rollback — tenant tidak pernah "setengah siap".
 * Status kesiapan dihitung ulang dengan {@see assertReady()} dan disimpan di
 * tenants.readiness_status sehingga jalur lain (mis. webhook order) dapat
 * menolak tenant yang belum siap alih-alih menabrak abort 500.
 */
class TenantProvisioningService
{
    public const READINESS_PENDING = 'pending';

    public const READINESS_READY = 'ready';

    public function __construct(
        private TenantContext $tenantContext,
        private RoleService $roleService,
    ) {}

    /**
     * Lengkapi baseline tenant baru di dalam konteks tenant tersebut.
     * WAJIB dipanggil dari dalam transaksi bisnis provisioning.
     */
    public function provisionBaseline(Tenant $tenant, User $owner): void
    {
        $this->tenantContext->runAs($tenant->id, null, function () use ($tenant, $owner) {
            // 1. Role standar (8 role) — sumber kebenaran RoleService, supaya
            //    provisioning tidak pernah berbeda dari tenant:provision-roles.
            $this->roleService->provisionDefaultRoles($tenant);

            // 2. Store pertama sudah dibuat pemanggil; di sini hanya baseline
            //    setting yang bergantung pada store tersebut.
            $store = Store::query()->where('tenant_id', $tenant->id)->orderBy('id')->first();

            // 3. User sistem: kanal order masuk & self-order pelanggan.
            $systemRoleId = Role::query()->where('tenant_id', $tenant->id)->where('name', 'Kasir')->value('id')
                ?? $owner->role_id;

            $this->provisionSystemUser($tenant, 'self_order', $systemRoleId, $store?->id);
            $this->provisionSystemUser($tenant, 'channel_order', $systemRoleId, $store?->id);

            // 4. Baseline setting yang dibutuhkan operasi harian.
            $this->provisionBaselineSettings($tenant, $store);

            // 5. Inisialisasi langganan awal (SaaS subscription)
            $planCode = $tenant->subscription_plan ?? 'standard_trial';
            $plan = Plan::where('code', $planCode)->first();
            if ($plan && ! TenantSubscription::where('tenant_id', $tenant->id)->exists()) {
                TenantSubscription::create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $plan->id,
                    'status' => TenantSubscription::STATUS_TRIALING,
                    'starts_at' => now(),
                    'trial_ends_at' => now()->addDays(14),
                ]);
            }
        });
    }

    /**
     * Nama user sistem yang deterministik dan sudah dipahami aplikasi:
     * SystemUserResolver mencari email `selforder.t{tenant}@...` /
     * `channel.t{tenant}@...` untuk tenant selain tenant 1.
     */
    public function systemUserEmail(Tenant $tenant, string $kind): string
    {
        return $kind === 'channel_order'
            ? SystemUserResolver::channelEmail((int) $tenant->id)
            : SystemUserResolver::selfOrderEmail((int) $tenant->id);
    }

    public function systemUserName(string $kind): string
    {
        return $kind === 'channel_order' ? 'Channel Order System' : 'Self Order System';
    }

    public function evaluateReadiness(Tenant $tenant): array
    {
        $missing = $this->tenantContext->runAs($tenant->id, null, function () use ($tenant) {
            $missing = [];

            $ownerRole = Role::query()->where('tenant_id', $tenant->id)->where('name', 'Owner')->first();
            if ($ownerRole === null || ! $ownerRole->hasPermission('dashboard')) {
                $missing[] = 'owner_role';
            }

            if (! Store::query()->where('tenant_id', $tenant->id)->exists()) {
                $missing[] = 'first_store';
            }

            foreach (['self_order', 'channel_order'] as $kind) {
                $email = $this->systemUserEmail($tenant, $kind);
                if (! $this->tenantContext->runWithoutTenant(
                    fn () => User::query()->where('email', $email)->where('tenant_id', $tenant->id)->exists()
                )) {
                    $missing[] = $kind.'_user';
                }
            }

            foreach ([
                'business_name',
                'default_sales_channel',
                'tax_enabled',
            ] as $settingKey) {
                if (! Setting::query()->where('tenant_id', $tenant->id)->where('key', $settingKey)->exists()) {
                    $missing[] = 'setting_'.$settingKey;
                }
            }

            return $missing;
        });

        return ['ready' => $missing === [], 'missing' => $missing];
    }

    /**
     * Tegakkan status kesiapan pada record tenant. Dipanggil setelah
     * transaksi bisnis commit — kegagalannya TIDAK membatalkan tenant
     * (tenant tetap ada; statusnya 'pending' dan dapat diperbaiki
     * `tenant:provision-system-users`).
     */
    public function refreshReadiness(Tenant $tenant): array
    {
        $result = $this->evaluateReadiness($tenant);

        Tenant::query()->whereKey($tenant->id)->update([
            'readiness_status' => $result['ready'] ? self::READINESS_READY : self::READINESS_PENDING,
            'readiness_checked_at' => now(),
        ]);

        if (! $result['ready']) {
            Log::warning('Tenant provisioning baseline tidak lengkap.', [
                'tenant_id' => $tenant->id,
                'missing' => $result['missing'],
            ]);
        }

        return $result;
    }

    private function provisionSystemUser(Tenant $tenant, string $kind, ?int $roleId, ?int $storeId): User
    {
        $email = $this->systemUserEmail($tenant, $kind);

        $user = $this->tenantContext->runWithoutTenant(
            fn () => User::query()->where('tenant_id', $tenant->id)->where('email', $email)->first()
        );

        if ($user !== null) {
            return $user; // idempoten (mis. dipanggil ulang oleh reconcile)
        }

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => $this->systemUserName($kind),
            'email' => $email,
            // Password acak + email belum terverifikasi: akun ini TIDAK boleh
            // dipakai login, hanya untuk atribusi transaksi (pola seeder lama).
            'password_hash' => bcrypt(str()->random(40)),
            'role_id' => $roleId,
            'is_active' => true,
            'email_verified_at' => null,
            'join_date' => now()->toDateString(),
        ]);

        return $user;
    }

    private function provisionBaselineSettings(Tenant $tenant, ?Store $store): void
    {
        $baseline = [
            ['key' => 'business_name', 'value' => $tenant->name, 'type' => 'string'],
            ['key' => 'default_sales_channel', 'value' => 'DINE_IN', 'type' => 'string'],
            ['key' => 'tax_enabled', 'value' => 'false', 'type' => 'boolean'],
        ];

        foreach ($baseline as $setting) {
            Setting::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'key' => $setting['key']],
                ['value' => $setting['value'], 'type' => $setting['type']],
            );
        }
    }
}
