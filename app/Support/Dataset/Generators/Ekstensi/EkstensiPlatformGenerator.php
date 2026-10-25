<?php

declare(strict_types=1);

namespace App\Support\Dataset\Generators\Ekstensi;

use App\Support\Dataset\DatasetConfig;
use App\Support\Dataset\DatasetWriter;
use App\Support\Dataset\Generators\Ekstensi\Concerns\ResolvesDatasetContext;
use App\Support\Dataset\SeededRandom;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sisi platform/akun: RBAC granular, undangan pegawai, identitas OAuth,
 * verifikasi email, onboarding owner, riwayat provisioning, entitlement
 * override, dan antrean email transaksional.
 */
final class EkstensiPlatformGenerator
{
    use ResolvesDatasetContext;

    /** @var array<string,array<int,string>> role_code => permission codes */
    private const ROLE_PERMISSIONS = [
        'ROL-OWNER' => ['*'],
        'ROL-MANAGER' => [
            'dashboard.view', 'reports.view', 'reports.export', 'orders.view', 'orders.manage',
            'shifts.view', 'shifts.manage', 'inventory.view', 'inventory.manage',
            'discounts.view', 'discounts.manage', 'users.view', 'purchasing.view',
        ],
        'ROL-SUPERVISOR' => [
            'dashboard.view', 'orders.view', 'orders.manage', 'orders.void', 'shifts.view',
            'shifts.manage', 'reports.view',
        ],
        'ROL-KASIR' => [
            'orders.create', 'orders.view', 'payments.create', 'payments.refund', 'shifts.own',
        ],
        'ROL-WAITER' => ['orders.create', 'orders.view', 'tables.view'],
        'ROL-BARISTA' => ['orders.view', 'kds.view', 'kds.update', 'inventory.view'],
        'ROL-COOK' => ['orders.view', 'kds.view', 'kds.update'],
        'ROL-INVENTORY' => [
            'inventory.view', 'inventory.manage', 'inventory.opname', 'purchasing.view', 'purchasing.manage',
        ],
    ];

    /** @var array<string,int> permission_code => id */
    private array $permissionIds = [];

    public function __construct(
        private readonly DatasetConfig $config,
        private readonly DatasetWriter $writer,
    ) {}

    public function generate(int $tenantId, int $ownerId): void
    {
        $this->ensurePermissions();
        $this->ensureRolePermissions($tenantId);
        $this->ensureOnboardingTokens($tenantId, $ownerId);
        $this->ensureProvisioningRequests($tenantId, $ownerId);
        $this->ensureEntitlementOverrides($tenantId);
        $this->ensureOauthIdentities($tenantId, $ownerId);
        $this->ensureEmailVerificationOtps($tenantId, $ownerId);
        $this->ensureInvitations($tenantId, $ownerId);
        $this->ensurePasswordResetTokens($tenantId, $ownerId);
        $this->ensureEmailOutbox($tenantId, $ownerId);
        $this->ensureFrameworkArtifacts($tenantId, $ownerId);
    }

    private function ensurePermissions(): void
    {
        $catalog = [
            '*' => 'Akses penuh ke seluruh modul',
            'dashboard.view' => 'Melihat dashboard',
            'reports.view' => 'Melihat laporan',
            'reports.export' => 'Mengekspor laporan',
            'orders.create' => 'Membuat transaksi',
            'orders.view' => 'Melihat transaksi',
            'orders.manage' => 'Mengelola transaksi',
            'orders.void' => 'Membatalkan transaksi',
            'payments.create' => 'Menerima pembayaran',
            'payments.refund' => 'Memproses refund',
            'shifts.own' => 'Mengelola shift sendiri',
            'shifts.view' => 'Melihat shift',
            'shifts.manage' => 'Mengelola shift semua kasir',
            'tables.view' => 'Melihat status meja',
            'kds.view' => 'Melihat kitchen display',
            'kds.update' => 'Memperbarui status pesanan di dapur',
            'inventory.view' => 'Melihat stok',
            'inventory.manage' => 'Mengelola stok',
            'inventory.opname' => 'Melakukan stock opname',
            'discounts.view' => 'Melihat promo',
            'discounts.manage' => 'Mengelola promo',
            'users.view' => 'Melihat data pegawai',
            'purchasing.view' => 'Melihat pengadaan',
            'purchasing.manage' => 'Mengelola pengadaan',
            'settings.manage' => 'Mengubah pengaturan',
        ];

        foreach ($catalog as $code => $description) {
            $existing = DB::table('permissions')->where('permission_code', $code)->first();
            if ($existing) {
                $this->permissionIds[$code] = (int) $existing->id;

                continue;
            }
            $this->permissionIds[$code] = (int) DB::table('permissions')->insertGetId([
                'permission_code' => $code,
                'description' => $description,
            ]);
            $this->writer->markWritten('permissions', 1);
        }
    }

    private function ensureRolePermissions(int $tenantId): void
    {
        $roles = DB::table('roles')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->pluck('id', 'role_code');

        $rows = [];
        foreach (self::ROLE_PERMISSIONS as $roleCode => $codes) {
            $roleId = (int) ($roles[$roleCode] ?? 0);
            if ($roleId === 0) {
                continue;
            }
            foreach ($codes as $code) {
                if (! isset($this->permissionIds[$code])) {
                    continue;
                }
                $rows[] = ['role_id' => $roleId, 'permission_id' => $this->permissionIds[$code]];
            }
        }

        if ($rows === []) {
            return;
        }

        $existing = DB::table('role_permissions')->whereIn('role_id', array_column($rows, 'role_id'))
            ->get(['role_id', 'permission_id'])
            ->map(fn ($r) => $r->role_id.':'.$r->permission_id)->flip()->all();

        $new = array_values(array_filter($rows, fn ($r) => ! isset($existing[$r['role_id'].':'.$r['permission_id']])));
        foreach (array_chunk($new, $this->writer->batchSize()) as $chunk) {
            DB::table('role_permissions')->insert($chunk);
            $this->writer->markWritten('role_permissions', count($chunk));
        }
    }

    private function ensureOnboardingTokens(int $tenantId, int $ownerId): void
    {
        $exists = DB::table('owner_onboarding_tokens')->where('tenant_id', $tenantId)
            ->where('user_id', $ownerId)->exists();
        if ($exists) {
            return;
        }

        $createdAt = $this->config->startDate->subDays(15);

        DB::table('owner_onboarding_tokens')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $ownerId,
            'token_hash' => hash('sha256', 'onboarding|'.$tenantId.'|'.$ownerId),
            'expires_at' => $createdAt->addDays(7),
            'consumed_at' => $createdAt->addDays(1),
            'send_attempts' => 1,
            'created_at' => $createdAt,
            'updated_at' => $createdAt->addDays(1),
        ]);
        $this->writer->markWritten('owner_onboarding_tokens', 1);
    }

    /** Riwayat permintaan provisioning tenant (audit pembuatan akun). */
    private function ensureProvisioningRequests(int $tenantId, int $ownerId): void
    {
        $ownerEmail = (string) DB::table('users')->where('id', $ownerId)->value('email');
        $idempotencyKey = 'ds-prov:'.$tenantId;
        $exists = DB::table('provisioning_requests')->where('idempotency_key', $idempotencyKey)->exists();
        if ($exists) {
            return;
        }

        $requestedAt = $this->config->startDate->subDays(15);

        // Nonce idempotensi provisioning (consumer uniqueness guard).
        $nonce = 'dsprov'.substr(hash('sha256', 'prov|'.$tenantId), 0, 26);
        if (! DB::table('provisioning_nonces')->where('nonce', $nonce)->exists()) {
            DB::table('provisioning_nonces')->insert([
                'nonce' => $nonce,
                'expires_at_epoch' => $requestedAt->addDays(30)->getTimestamp(),
                'created_at' => $requestedAt,
                'updated_at' => $requestedAt,
            ]);
            $this->writer->markWritten('provisioning_nonces', 1);
        }

        DB::table('provisioning_requests')->insert([
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => hash('sha256', $ownerEmail.'|'.$tenantId),
            'owner_email' => $ownerEmail,
            'status' => 'completed',
            'result_tenant_id' => $tenantId,
            'result_user_id' => $ownerId,
            'correlation_id' => (string) Str::uuid(),
            'lease_expires_at' => $requestedAt->addMinutes(10),
            'error_message' => null,
            'started_at' => $requestedAt,
            'completed_at' => $requestedAt->addMinutes(2),
            'attempt_count' => 1,
            'created_at' => $requestedAt,
            'updated_at' => $requestedAt->addMinutes(2),
        ]);
        $this->writer->markWritten('provisioning_requests', 1);
    }

    private function ensureEntitlementOverrides(int $tenantId): void
    {
        if ($this->tenantHasRows('tenant_entitlement_overrides', $tenantId)) {
            return;
        }

        $overrides = [
            ['max_stores', 'limit', '6', 'Akun multi-cabang pilot'],
            ['max_users', 'limit', '80', 'Kuota pegawai diperluas'],
            ['api_access', 'boolean', 'true', 'Integrasi channel aggregator'],
        ];

        foreach ($overrides as [$code, $type, $value, $reason]) {
            DB::table('tenant_entitlement_overrides')->insert([
                'tenant_id' => $tenantId,
                'feature_code' => $code,
                'feature_type' => $type,
                'value' => $value,
                'expires_at' => null,
                'reason' => $reason,
                'created_at' => $this->config->startDate->subDays(12),
                'updated_at' => $this->config->startDate->subDays(12),
            ]);
            $this->writer->markWritten('tenant_entitlement_overrides', 1);
        }
    }

    /**
     * Owner & manager login dengan Google (SSO) — mengisi identitas OAuth nyata
     * yang merujuk ke user sungguhan.
     */
    private function ensureOauthIdentities(int $tenantId, int $ownerId): void
    {
        if ($this->tenantHasRows('user_oauth_identities', $tenantId)) {
            return;
        }

        $users = DB::table('users')->where('tenant_id', $tenantId)
            ->whereIn('id', array_merge(
                [$ownerId],
                DB::table('users as u')->join('roles as r', 'r.id', '=', 'u.role_id')
                    ->where('u.tenant_id', $tenantId)->where('r.role_code', 'ROL-MANAGER')
                    ->limit(3)->pluck('u.id')->all()
            ))
            ->get(['id', 'email']);

        $linkedAt = $this->config->startDate->subDays(20);

        foreach ($users as $i => $user) {
            $provider = $i % 2 === 0 ? 'google' : 'microsoft';
            $exists = DB::table('user_oauth_identities')->where('provider', $provider)
                ->where('provider_subject', 'sub-'.$user->id)->exists();
            if ($exists) {
                continue;
            }

            DB::table('user_oauth_identities')->insert([
                'tenant_id' => $tenantId,
                'user_id' => (int) $user->id,
                'provider' => $provider,
                'provider_subject' => 'sub-'.$user->id,
                'provider_email' => $user->email,
                'provider_email_normalized' => strtolower((string) $user->email),
                'provider_email_verified' => 1,
                'is_private_relay' => 0,
                'linked_at' => $linkedAt,
                'last_used_at' => $this->config->effectiveEndDate()->subDays(1),
                'created_at' => $linkedAt,
                'updated_at' => $linkedAt,
            ]);
            $this->writer->markWritten('user_oauth_identities', 1);
        }
    }

    /** OTP verifikasi email saat registrasi owner (sudah dikonsumsi). */
    private function ensureEmailVerificationOtps(int $tenantId, int $ownerId): void
    {
        if ($this->tenantHasRows('email_verification_otps', $tenantId)) {
            return;
        }

        $email = (string) DB::table('users')->where('id', $ownerId)->value('email');
        $createdAt = $this->config->startDate->subDays(15);

        DB::table('email_verification_otps')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $ownerId,
            'email' => $email,
            'email_normalized' => strtolower($email),
            'otp_code_hash' => hash('sha256', 'otp|'.$tenantId.'|'.$ownerId),
            'action' => 'registration',
            'attempts' => 1,
            'max_attempts' => 5,
            'expires_at' => $createdAt->addMinutes(10),
            'consumed_at' => $createdAt->addMinutes(3),
            'superseded_at' => null,
            'locked_at' => null,
            'ip_address' => '10.10.0.'.$tenantId,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
            'created_at' => $createdAt,
            'updated_at' => $createdAt->addMinutes(3),
        ]);
        $this->writer->markWritten('email_verification_otps', 1);
    }

    /**
     * Undangan pegawai baru per cabang: sebagian sudah diterima, sebagian
     * masih pending, sebagin kedaluwarsa/dicabut.
     */
    private function ensureInvitations(int $tenantId, int $ownerId): void
    {
        if ($this->tenantHasRows('user_invitations', $tenantId)) {
            return;
        }

        $rng = new SeededRandom($this->config->seed ^ crc32('invitations'.'|'.$tenantId));
        $stores = DB::table('stores')->where('tenant_id', $tenantId)->whereNull('deleted_at')->get(['id', 'code']);
        $roles = DB::table('roles')->where('tenant_id', $tenantId)->whereNull('deleted_at')->pluck('id', 'role_code');
        $inviter = $this->userWithRole($tenantId, 'ROL-MANAGER', $ownerId);
        $end = $this->config->effectiveEndDate();

        $plans = [
            ['ROL-KASIR', 'Dewi Lestari'], ['ROL-KASIR', 'Rangga Pratama'], ['ROL-BARISTA', 'Yoga Saputra'],
            ['ROL-WAITER', 'Intan Permata'], ['ROL-COOK', 'Bagas Nugroho'], ['ROL-INVENTORY', 'Rizky Ramadhan'],
            ['ROL-SUPERVISOR', 'Ayu Wulandari'], ['ROL-KASIR', 'Fajar Hidayat'],
        ];

        foreach ($stores as $store) {
            foreach ($plans as $i => [$roleCode, $name]) {
                if ($rng->bool(0.35)) {
                    continue;
                }

                $invitedAt = $this->randomDay($rng, $this->config->startDate, $end, false)->setTime(10, $rng->int(0, 59));
                $status = $rng->weighted(['accepted' => 50, 'pending' => 25, 'expired' => 15, 'revoked' => 6, 'superseded' => 4]);
                $email = sprintf('%s.%s.%d@example.invalid', Str::slug($name, '.'), strtolower($store->code), $i + 1);

                $invitationId = (int) DB::table('user_invitations')->insertGetId([
                    'tenant_id' => $tenantId,
                    'role_id' => (int) ($roles[$roleCode] ?? $roles['ROL-KASIR'] ?? 0),
                    'invited_by_user_id' => $inviter,
                    'email' => $email,
                    'email_normalized' => strtolower($email),
                    'name' => $name,
                    'token_hash' => hash('sha256', $tenantId.'|'.$email.'|'.$invitedAt->timestamp),
                    'status' => $status,
                    'expires_at' => $invitedAt->addDays(7),
                    'accepted_at' => $status === 'accepted' ? $invitedAt->addDays($rng->int(0, 5)) : null,
                    'revoked_at' => $status === 'revoked' ? $invitedAt->addDays(1) : null,
                    'superseded_at' => $status === 'superseded' ? $invitedAt->addDays(2) : null,
                    'created_at' => $invitedAt,
                    'updated_at' => $invitedAt,
                ]);
                $this->writer->markWritten('user_invitations', 1);

                DB::table('user_invitation_stores')->insert([
                    'tenant_id' => $tenantId,
                    'user_invitation_id' => $invitationId,
                    'store_id' => (int) $store->id,
                    'created_at' => $invitedAt,
                    'updated_at' => $invitedAt,
                ]);
                $this->writer->markWritten('user_invitation_stores', 1);
            }
        }
    }

    /** Token reset password yang sudah kedaluwarsa/terpakai (jejak audit). */
    private function ensurePasswordResetTokens(int $tenantId, int $ownerId): void
    {
        $rng = new SeededRandom($this->config->seed ^ crc32('pwreset'.'|'.$tenantId));
        $users = DB::table('users')->where('tenant_id', $tenantId)
            ->where('id', '<>', $ownerId)->orderBy('id')->limit(10)->get(['id', 'email']);

        foreach ($users as $user) {
            $token = hash('sha256', 'reset|'.$tenantId.'|'.$user->id);
            if (DB::table('password_reset_tokens')->where('email', $user->email)->exists()) {
                continue;
            }
            $at = $this->randomDay($rng, $this->config->startDate, $this->config->effectiveEndDate());
            DB::table('password_reset_tokens')->insert([
                'email' => $user->email,
                'token' => $token,
                'created_at' => $at,
            ]);
            $this->writer->markWritten('password_reset_tokens', 1);
        }
    }

    /** Antrean email transaksional (undangan, reset password, laporan bulanan). */
    private function ensureEmailOutbox(int $tenantId, int $ownerId): void
    {
        if (DB::table('email_outbox')->where('tenant_id', $tenantId)->exists()) {
            return;
        }

        $rng = new SeededRandom($this->config->seed ^ crc32('emailoutbox'.'|'.$tenantId));
        $rows = [];

        $invitations = DB::table('user_invitations')->where('tenant_id', $tenantId)->orderBy('id')->limit(60)->get();

        foreach ($invitations as $invitation) {
            $at = $invitation->created_at
                ? CarbonImmutable::parse($invitation->created_at)
                : $this->config->startDate;
            $rows[] = [
                'event_type' => 'user.invitation',
                'recipient_email' => $invitation->email,
                'subject' => 'Undangan bergabung sebagai staf Apeiron POS',
                'body_text' => 'Halo '.$invitation->name.', Anda diundang bergabung. Tautan berlaku 7 hari.',
                'tenant_id' => $tenantId,
                'user_id' => null,
                'status' => $rng->weighted(['sent' => 88, 'failed' => 6, 'pending' => 6]),
                'attempts' => 1,
                'last_error' => null,
                'next_attempt_at' => null,
                'lease_expires_at' => null,
                'sent_at' => $at->addMinutes(1),
                'created_at' => $at,
                'updated_at' => $at->addMinutes(1),
            ];
        }

        $owner = DB::table('users')->where('id', $ownerId)->first(['id', 'email', 'name']);
        $staff = DB::table('users')->where('tenant_id', $tenantId)->where('id', '<>', $ownerId)
            ->orderBy('id')->limit(20)->get(['id', 'email', 'name']);

        foreach ($staff as $i => $user) {
            $at = $this->randomDay($rng, $this->config->startDate, $this->config->effectiveEndDate());
            $rows[] = [
                'event_type' => 'user.password_reset',
                'recipient_email' => $user->email,
                'subject' => 'Permintaan reset password akun POS',
                'body_text' => 'Ada permintaan reset password untuk akun Anda. Abaikan bila bukan Anda.',
                'tenant_id' => $tenantId,
                'user_id' => (int) $user->id,
                'status' => 'sent',
                'attempts' => 1,
                'last_error' => null,
                'next_attempt_at' => null,
                'lease_expires_at' => null,
                'sent_at' => $at,
                'created_at' => $at,
                'updated_at' => $at,
            ];
        }

        $cursor = $this->monthStart($this->config->startDate);
        while ($cursor->lessThanOrEqualTo($this->config->effectiveEndDate())) {
            $at = $cursor->endOfMonth()->setTime(6, 30);
            $rows[] = [
                'event_type' => 'report.monthly_ready',
                'recipient_email' => (string) ($owner->email ?? 'owner@example.invalid'),
                'subject' => 'Laporan penjualan bulan '.$cursor->format('F Y').' siap diunduh',
                'body_text' => 'Rekap transaksi bulan '.$cursor->format('F Y').' sudah tersedia di dashboard.',
                'tenant_id' => $tenantId,
                'user_id' => $ownerId,
                'status' => 'sent',
                'attempts' => 1,
                'last_error' => null,
                'next_attempt_at' => null,
                'lease_expires_at' => null,
                'sent_at' => $at,
                'created_at' => $at,
                'updated_at' => $at,
            ];
            $cursor = $this->nextMonthStart($cursor);
        }

        foreach (array_chunk($rows, $this->writer->batchSize()) as $chunk) {
            DB::table('email_outbox')->insert($chunk);
            $this->writer->markWritten('email_outbox', count($chunk));
        }
    }

    private function ensureFrameworkArtifacts(int $tenantId, int $ownerId): void
    {
        // 1. job_batches: rekap batch job laporan bulanan
        if (DB::table('job_batches')->count() === 0) {
            $batchId = (string) Str::uuid();
            $now = now()->timestamp;
            DB::table('job_batches')->insert([
                'id' => $batchId,
                'name' => 'MonthlySalesAggregateJobBatch',
                'total_jobs' => 12,
                'pending_jobs' => 0,
                'failed_jobs' => 0,
                'failed_job_ids' => '[]',
                'options' => serialize(['tenant_id' => $tenantId, 'source' => 'monthly_reconciliation']),
                'cancelled_at' => null,
                'created_at' => $now - 86400 * 30,
                'finished_at' => $now - 86400 * 30 + 120,
            ]);
            $this->writer->markWritten('job_batches', 1);
        }

        // 2. failed_jobs: simulasi kegagalan webhook pihak ketiga (retry habis)
        if (DB::table('failed_jobs')->count() === 0) {
            $failedId = (string) Str::uuid();
            DB::table('failed_jobs')->insert([
                'uuid' => $failedId,
                'connection' => 'database',
                'queue' => 'notifications',
                'payload' => json_encode([
                    'displayName' => 'App\\Jobs\\SendWebhookNotification',
                    'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                    'data' => [
                        'commandName' => 'App\\Jobs\\SendWebhookNotification',
                        'command' => serialize(['tenant_id' => $tenantId, 'event' => 'order.paid', 'attempt' => 3]),
                    ],
                ]),
                'exception' => "Illuminate\\Http\\Client\\ConnectionException: cURL error 28: Operation timed out after 5000 milliseconds with 0 bytes received in /app/Jobs/SendWebhookNotification.php:45\nStack trace:\n#0 /vendor/laravel/framework/src/Illuminate/Http/Client/PendingRequest.php(934): Illuminate\\Http\\Client\\PendingRequest->send()",
                'failed_at' => now()->subDays(45),
            ]);
            $this->writer->markWritten('failed_jobs', 1);
        }

        // 3. cache_locks: lock operasional sistem / dataset
        if (DB::table('cache_locks')->count() === 0) {
            DB::table('cache_locks')->insert([
                'key' => 'dataset:generation:lock:tenant:'.$tenantId,
                'owner' => 'dataset_worker_'.getmypid(),
                'expiration' => now()->addMinutes(10)->timestamp,
            ]);
            $this->writer->markWritten('cache_locks', 1);
        }
    }
}
