<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Core\Context\TenantContext;
use App\Models\Company;
use App\Models\EmailOutbox;
use App\Models\OwnerOnboardingToken;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RoleService;
use App\Support\EmailNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * TenantProvisioningService — Layanan orkestrasi alur registrasi dan penyediaan (provisioning) tenant SaaS baru.
 *
 * Sesuai Dokumen Arsitektur WF-CORE-01 & FR-CORE-001:
 * - Validasi email unik global.
 * - Pembungkus transaksi atomik: Tenant -> Store Pertama -> Role Owner -> User Owner -> Token Onboarding -> Outbox.
 * - Inisialisasi pengaturan baseline operasional.
 * - Pencatatan log aktivitas append-only.
 */
class TenantProvisioningService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RoleService $roleService,
        private readonly ActivityLogService $activityLogService
    ) {}

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
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function provisionTenant(
        string $tenantName,
        string $ownerName,
        string $ownerEmail,
        ?string $ownerPhone = null,
        ?string $idempotencyKey = null,
    ): array {
        $normalizedEmail = EmailNormalizer::normalize($ownerEmail);

        // 1. Validasi email unik secara global sebelum membuka transaksi
        $existingUser = $this->tenantContext->runWithoutTenant(function () use ($normalizedEmail) {
            return User::withoutGlobalScopes()
                ->where('email_normalized', $normalizedEmail)
                ->whereNull('deleted_at')
                ->first();
        });

        if ($existingUser !== null) {
            throw new InvalidArgumentException("Email {$ownerEmail} sudah terdaftar dalam sistem.");
        }

        $idempotency = $idempotencyKey ?: (string) Str::uuid();

        // 2. Eksekusi seluruh alur pembuatan di dalam DB::transaction atomik
        return $this->tenantContext->runWithoutTenant(function () use ($tenantName, $ownerName, $ownerEmail, $ownerPhone, $idempotency) {
            return DB::transaction(function () use ($tenantName, $ownerName, $ownerEmail, $ownerPhone, $idempotency) {
                // a. Buat Tenant (Tier 1)
                $tenant = Tenant::create([
                    'name' => $tenantName,
                    'provisioning_idempotency_key' => $idempotency,
                    'readiness_status' => 'ready',
                ]);

                // b. Beralih ke konteks tenant baru untuk entitas Tier 2
                return $this->tenantContext->runAs($tenant->id, null, function () use ($tenant, $tenantName, $ownerName, $ownerEmail, $ownerPhone) {
                    // c. Buat Role Owner dengan permission wildcard
                    $ownerRole = Role::firstOrCreate(
                        ['name' => 'Owner', 'tenant_id' => $tenant->id],
                        [
                            'role_code' => 'ROL-OWNER',
                            'description' => 'Pemilik bisnis — akses penuh',
                            'permissions' => ['*'],
                            'is_active' => true,
                        ]
                    );

                    // d. Buat role standar bawaan (Manager, Kasir, Barista, dsb)
                    $this->roleService->provisionDefaultRoles($tenant);

                    // d2. Buat Default Legal Company (Tier 2 DEC-001)
                    $company = Company::firstOrCreate(
                        ['tenant_id' => $tenant->id, 'is_default' => true],
                        [
                            'company_code' => 'CMP-'.str_pad((string) $tenant->id, 4, '0', STR_PAD_LEFT),
                            'company_name' => $tenantName,
                            'legal_name' => $tenantName,
                            'is_active' => true,
                            'is_default' => true,
                        ]
                    );

                    // e. Buat Toko / Cabang pertama (Store)
                    $store = Store::create([
                        'tenant_id' => $tenant->id,
                        'name' => $tenantName,
                        'is_initial' => true,
                        'is_active' => true,
                    ]);

                    // f. Buat User Owner
                    $owner = User::create([
                        'tenant_id' => $tenant->id,
                        'name' => $ownerName,
                        'email' => $ownerEmail,
                        'phone_number' => $ownerPhone,
                        'password' => null, // Ditetapkan oleh Owner via token onboarding
                        'role_id' => $ownerRole->id,
                        'is_active' => true,
                        'email_verified_at' => null,
                        'join_date' => now()->toDateString(),
                    ]);

                    // Kaitkan user owner ke store pertama di pivot user_stores
                    $owner->stores()->attach($store->id, ['tenant_id' => $tenant->id]);

                    // g. Buat token onboarding (magic link) dengan hash SHA-256
                    $plaintextToken = Str::random(48);
                    $tokenHash = hash('sha256', $plaintextToken);

                    OwnerOnboardingToken::create([
                        'tenant_id' => $tenant->id,
                        'user_id' => $owner->id,
                        'token_hash' => $tokenHash,
                        'expires_at' => now()->addHours(48),
                        'send_attempts' => 1,
                    ]);

                    // h. Masukkan email undangan aktivasi ke EmailOutbox
                    $outbox = EmailOutbox::create([
                        'event_type' => 'owner_onboarding',
                        'recipient_email' => $ownerEmail,
                        'subject' => 'Aktivasi akun Apeiron System — '.$tenant->name,
                        'body_text' => "Halo {$ownerName},\n\nAkun Apeiron System untuk bisnis \"{$tenant->name}\" telah dibuat.\nSilakan atur kata sandi Anda melalui tautan aktivasi berikut:\n".url('/onboarding/set-password/'.$plaintextToken)."\n\nTautan berlaku selama 48 jam.\n\n— Tim Apeiron System",
                        'tenant_id' => $tenant->id,
                        'user_id' => $owner->id,
                        'status' => EmailOutbox::STATUS_PENDING,
                        'attempts' => 0,
                        'next_attempt_at' => now(),
                    ]);

                    // i. Pengaturan baseline tenant
                    $this->seedBaselineSettings($tenant);

                    // j. Log aktivitas mutasi
                    $this->activityLogService->log(
                        tenantId: (int) $tenant->id,
                        action: 'tenant.provisioned',
                        userId: (int) $owner->id,
                        storeId: (int) $store->id,
                        subjectType: Tenant::class,
                        subjectId: (int) $tenant->id,
                        description: "Tenant {$tenant->name} berhasil diprovisioning bersama cabang {$store->name} dan Owner {$owner->name}."
                    );

                    return [
                        'tenant' => $tenant,
                        'store' => $store,
                        'owner' => $owner,
                        'onboarding_token' => $plaintextToken,
                        'outbox' => $outbox,
                    ];
                });
            });
        });
    }

    private function seedBaselineSettings(Tenant $tenant): void
    {
        $baseline = [
            ['key' => 'business_name', 'value' => $tenant->name, 'type' => 'string'],
            ['key' => 'default_sales_channel', 'value' => 'DINE_IN', 'type' => 'string'],
            ['key' => 'tax_enabled', 'value' => 'false', 'type' => 'boolean'],
        ];

        foreach ($baseline as $item) {
            Setting::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'key' => $item['key']],
                ['value' => $item['value'], 'type' => $item['type']]
            );
        }
    }
}
