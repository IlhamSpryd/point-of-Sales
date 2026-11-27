<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Core\Context\TenantContext;
use App\Modules\Organization\Models\Company;
use App\Modules\Organization\Models\EmailOutbox;
use App\Modules\Organization\Models\OwnerOnboardingToken;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Setting;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Subscription\Models\Plan;
use App\Modules\Subscription\Models\TenantSubscription;
use App\Support\EmailNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * TenantProvisioningService — Layanan sentral registrasi, provisioning, dan kesiapan tenant.
 * Menggabungkan alur registrasi awal (WF-CORE-01) dan penegakan baseline kesiapan (XD-02 / DB-002).
 */
class TenantProvisioningService
{
    public const READINESS_PENDING = 'pending';

    public const READINESS_READY = 'ready';

    private ActivityLogService $activityLogService;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RoleService $roleService,
        ?ActivityLogService $activityLogService = null
    ) {
        $this->activityLogService = $activityLogService ?? app(ActivityLogService::class);
    }

    /**
     * Daftarkan dan lakukan provisioning tenant SaaS baru secara atomik.
     *
     * @return array{
     *     tenant: Tenant,
     *     store: Store,
     *     owner: User,
     *     onboarding_token: string,
     *     outbox: EmailOutbox
     * }
     */
    public function provisionTenant(
        string $tenantName,
        string $ownerName,
        string $ownerEmail,
        ?string $ownerPhone = null,
        ?string $idempotencyKey = null,
    ): array {
        $normalizedEmail = EmailNormalizer::normalize($ownerEmail);

        if (User::where('email', $normalizedEmail)->exists()) {
            throw new InvalidArgumentException("Email [{$normalizedEmail}] sudah terdaftar di sistem.");
        }

        // @tenant-bypass: superadmin
        return $this->tenantContext->runWithoutTenant(function () use ($tenantName, $ownerName, $normalizedEmail, $ownerPhone, $idempotencyKey) {
            return DB::transaction(function () use ($tenantName, $ownerName, $normalizedEmail, $ownerPhone, $idempotencyKey) {
                $slug = Str::slug($tenantName);
                $baseSlug = $slug;
                $counter = 1;
                while (Tenant::where('slug', $slug)->exists()) {
                    $slug = "{$baseSlug}-{$counter}";
                    $counter++;
                }

                $tenant = Tenant::create([
                    'name' => $tenantName,
                    'slug' => $slug,
                    'status' => 'pending_activation',
                ]);

                return $this->tenantContext->runAs((int) $tenant->id, null, function () use ($tenant, $tenantName, $ownerName, $normalizedEmail, $ownerPhone, $idempotencyKey) {
                    $company = Company::create([
                        'tenant_id' => $tenant->id,
                        'company_name' => $tenantName,
                        'company_code' => strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $tenantName) ?: 'CMP', 0, 8)),
                        'is_active' => true,
                        'is_default' => true,
                    ]);

                    $store = Store::create([
                        'tenant_id' => $tenant->id,
                        'company_id' => $company->id,
                        'name' => 'Outlet Utama',
                        'branch_code' => 'OUT-001',
                        'is_active' => true,
                    ]);

                    $ownerRole = Role::create([
                        'tenant_id' => $tenant->id,
                        'name' => 'Owner',
                        'guard_name' => 'web',
                        'permissions' => ['*'],
                        'is_system' => true,
                        'is_admin' => true,
                    ]);

                    $this->roleService->provisionDefaultRoles($tenant);

                    $randomPassword = Str::random(32);
                    $owner = User::create([
                        'tenant_id' => $tenant->id,
                        'name' => $ownerName,
                        'email' => $normalizedEmail,
                        'phone' => $ownerPhone,
                        'password' => bcrypt($randomPassword),
                        'role_id' => $ownerRole->id,
                        'status' => 'pending_activation',
                    ]);

                    $owner->stores()->attach($store->id, ['tenant_id' => $tenant->id]);

                    $tenant->update(['owner_user_id' => $owner->id]);

                    $rawToken = Str::random(64);
                    $tokenHash = hash('sha256', $rawToken);

                    OwnerOnboardingToken::create([
                        'tenant_id' => $tenant->id,
                        'user_id' => $owner->id,
                        'token_hash' => $tokenHash,
                        'expires_at' => now()->addDays(3),
                    ]);

                    $settingDefaults = [
                        'general.timezone' => 'Asia/Jakarta',
                        'general.currency' => 'IDR',
                        'pos.enable_quick_cash' => 'true',
                        'inventory.auto_deduct_sales' => 'true',
                    ];

                    foreach ($settingDefaults as $key => $value) {
                        Setting::create([
                            'tenant_id' => $tenant->id,
                            'key' => $key,
                            'value' => $value,
                        ]);
                    }

                    $outbox = EmailOutbox::create([
                        'tenant_id' => $tenant->id,
                        'user_id' => $owner->id,
                        'recipient_email' => $normalizedEmail,
                        'subject' => "Aktivasi Akun Apeiron Systems: {$tenantName}",
                        'body_text' => "Halo {$ownerName},\n\nAkun Apeiron System untuk bisnis \"{$tenantName}\" telah dibuat.\nSilakan atur kata sandi Anda melalui tautan aktivasi berikut:\n".url('/onboarding/set-password/'.$rawToken)."\n\nTautan berlaku selama 48 jam.\n\n— Tim Apeiron System",
                        'event_type' => 'tenant_onboarding',
                        'payload' => [
                            'owner_name' => $ownerName,
                            'tenant_name' => $tenantName,
                            'onboarding_token' => $rawToken,
                        ],
                        'status' => EmailOutbox::STATUS_PENDING,
                    ]);

                    $this->activityLogService->log(
                        tenantId: $tenant->id,
                        action: 'tenant.provisioned',
                        userId: $owner->id,
                        storeId: $store->id,
                        metadata: [
                            'tenant_id' => $tenant->id,
                            'owner_id' => $owner->id,
                            'store_id' => $store->id,
                            'idempotency_key' => $idempotencyKey,
                        ]
                    );

                    return [
                        'tenant' => $tenant,
                        'store' => $store,
                        'owner' => $owner,
                        'onboarding_token' => $rawToken,
                        'outbox' => $outbox,
                    ];
                });
            });
        });
    }

    /**
     * Lengkapi baseline tenant baru di dalam konteks tenant tersebut.
     * WAJIB dipanggil dari dalam transaksi bisnis provisioning.
     */
    public function provisionBaseline(Tenant $tenant, User $owner): void
    {
        $this->tenantContext->runAs($tenant->id, null, function () use ($tenant, $owner) {
            $this->roleService->provisionDefaultRoles($tenant);

            $store = Store::query()->where('tenant_id', $tenant->id)->orderBy('id')->first();

            Company::firstOrCreate(
                ['tenant_id' => $tenant->id, 'is_default' => true],
                [
                    'company_code' => 'CMP-'.str_pad((string) $tenant->id, 4, '0', STR_PAD_LEFT),
                    'company_name' => $tenant->name,
                    'legal_name' => $tenant->name,
                    'is_active' => true,
                    'is_default' => true,
                ]
            );

            $systemRoleId = Role::query()->where('tenant_id', $tenant->id)->where('name', 'Kasir')->value('id')
                ?? $owner->role_id;

            $this->provisionSystemUser($tenant, 'self_order', $systemRoleId, $store?->id);
            $this->provisionSystemUser($tenant, 'channel_order', $systemRoleId, $store?->id);

            $this->provisionBaselineSettings($tenant, $store);

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

    /**
     * @return array<string, mixed>
     */
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
                // @tenant-bypass: superadmin
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
     * @return array<string, mixed>
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

        // @tenant-bypass: superadmin
        $user = $this->tenantContext->runWithoutTenant(
            fn () => User::query()->where('tenant_id', $tenant->id)->where('email', $email)->first()
        );

        if ($user !== null) {
            return $user;
        }

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => $this->systemUserName($kind),
            'email' => $email,
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
