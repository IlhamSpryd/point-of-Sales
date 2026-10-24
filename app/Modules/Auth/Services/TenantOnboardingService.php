<?php

namespace App\Modules\Auth\Services;

use App\Jobs\ProcessProvisioningOutboxJob;
use App\Models\EmailOutbox;
use App\Models\OwnerOnboardingToken;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Support\EmailNormalizer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TenantOnboardingService
{
    /**
     * Dapatkan store awal milik tenant atau buatkan jika belum ada (idempoten).
     */
    public function getInitialStore(Tenant $tenant): Store
    {
        $store = Store::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->first();

        if ($store instanceof Store) {
            return $store;
        }

        $created = Store::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => ($tenant->name ?: 'Business').' - Main Branch',
            'code' => 'MAIN-01',
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
            'is_initial' => true,
        ]);

        return $created;
    }

    /**
     * Langkah 1: Simpan Profil Bisnis & Standar Operasional Tenant.
     */
    public function saveBusinessProfile(Tenant $tenant, array $data): Tenant
    {
        return DB::transaction(function () use ($tenant, $data) {
            $lockedTenant = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            if (! empty($data['business_name'])) {
                $lockedTenant->name = trim($data['business_name']);
            }
            if (! empty($data['country_code'])) {
                $lockedTenant->country_code = strtoupper(trim($data['country_code']));
            }

            $lockedTenant->onboarding_status = Tenant::ONBOARDING_INITIAL_BRANCH;
            $lockedTenant->save();

            // Simpan konfigurasi ke tabel settings
            $settings = [
                'business_name' => $lockedTenant->name,
                'business_category' => $data['business_category'] ?? 'fnb_cafe',
                'timezone' => $data['tenant_timezone'] ?? 'Asia/Jakarta',
                'currency_code' => $data['default_currency'] ?? 'IDR',
                'business_phone' => $data['owner_phone'] ?? null,
            ];

            foreach ($settings as $key => $value) {
                if ($value !== null) {
                    Setting::withoutTenantScope()->updateOrCreate(
                        ['tenant_id' => $tenant->id, 'key' => $key],
                        ['value' => (string) $value, 'type' => 'string']
                    );
                }
            }

            // Catat log audit
            $this->recordAuditLog(
                $tenant->id,
                'tenant.business_profile_configured',
                Tenant::class,
                $tenant->id,
                "Profil bisnis {$lockedTenant->name} telah diperbarui pada Onboarding Step 1."
            );

            return $lockedTenant;
        });
    }

    /**
     * Langkah 2: Konfigurasi Cabang Pertama & Selesaikan Onboarding Tenant.
     * Menggunakan update pada store awal secara idempoten (anti cabang duplikat).
     */
    public function saveInitialBranch(Tenant $tenant, array $data, ?User $owner = null): Store
    {
        return DB::transaction(function () use ($tenant, $data, $owner): Store {
            $lockedTenant = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            // Cari store awal dengan lock FOR UPDATE
            $store = Store::withoutTenantScope()
                ->where('tenant_id', $tenant->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($store === null) {
                $store = new Store;
                $store->tenant_id = $tenant->id;
                $store->is_initial = true;
            }

            $store->name = trim($data['store_name'] ?? ($tenant->name.' - Main Branch'));
            $store->code = strtoupper(trim($data['store_code'] ?? 'MAIN-01'));
            $store->timezone = $data['store_timezone'] ?? 'Asia/Jakarta';
            $store->business_date_cutoff = $data['business_date_cutoff'] ?? '00:00';
            $store->default_channel = $data['default_channel'] ?? 'dine_in';
            $store->address = $data['store_address'] ?? null;
            $store->phone = $data['store_phone'] ?? null;
            $store->is_active = true;
            $store->save();

            // Pastikan Owner terhubung ke cabang di user_stores
            if ($owner !== null) {
                DB::table('user_stores')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'store_id' => $store->id],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }

            // Transisi status onboarding tenant ke COMPLETED dan aktifkan akses
            $lockedTenant->onboarding_status = Tenant::ONBOARDING_COMPLETED;
            $lockedTenant->onboarding_completed_at = now();
            $lockedTenant->access_status = Tenant::ACCESS_ACTIVE;
            $lockedTenant->status = 'active';
            $lockedTenant->save();

            // Catat log audit
            $this->recordAuditLog(
                $tenant->id,
                'branch.initial_configured',
                Store::class,
                $store->id,
                "Cabang awal {$store->name} ({$store->code}) dikonfigurasi dan onboarding tenant selesai."
            );

            return $store;
        });
    }

    /**
     * Kirim ulang token onboarding secara mandiri (self-service resend).
     * Aman dari enumerasi email: selalu mengembalikan true.
     */
    public function resendOnboardingToken(string $email): bool
    {
        $normalizedEmail = EmailNormalizer::normalize($email);

        $user = User::withoutGlobalScopes()
            ->where('email_normalized', $normalizedEmail)
            ->first();

        // Jika user tidak ada atau email sudah terverifikasi, return true tanpa membocorkan status
        if ($user === null || ! $user->is_active || $user->email_verified_at !== null) {
            return true;
        }

        // Cari token yang ada
        $existingToken = OwnerOnboardingToken::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->first();

        // Cooldown 60 detik untuk mencegah spam
        if ($existingToken && $existingToken->updated_at && $existingToken->updated_at->gt(now()->subSeconds(60))) {
            return true;
        }

        // Buat token baru berkekuatan tinggi (64 karakter)
        $rawToken = Str::random(64);
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = now()->addMinutes(60);

        OwnerOnboardingToken::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $user->tenant_id, 'user_id' => $user->id],
            [
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
                'consumed_at' => null,
                'send_attempts' => 0,
            ]
        );

        // Masukkan email baru ke email_outbox
        $tenant = Tenant::find($user->tenant_id);
        $tenantName = $tenant->name ?? 'Apeiron POS';
        $activationUrl = url('/onboarding/set-password/'.$rawToken);

        $body = "Halo {$user->name},\n\n"
            ."Berikut adalah tautan baru untuk mengaktifkan akun Apeiron POS Anda pada bisnis \"{$tenantName}\":\n\n"
            ."{$activationUrl}\n\n"
            ."Tautan ini berlaku selama 60 menit dan hanya dapat digunakan satu kali.\n"
            ."Jika Anda tidak merasa mendaftar, silakan abaikan email ini.\n\n"
            ."Salam,\nTim Apeiron POS";

        $outbox = EmailOutbox::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'event_type' => 'owner_onboarding_resend',
            'recipient_email' => Crypt::encryptString($user->email),
            'subject' => 'Tautan Aktivasi Akun Apeiron POS Anda',
            'body_text' => $body,
            'status' => EmailOutbox::STATUS_PENDING,
            'attempts' => 0,
            'next_attempt_at' => now(),
        ]);

        try {
            ProcessProvisioningOutboxJob::dispatch($outbox->id, $rawToken);
        } catch (\Throwable $e) {
            Log::warning('Outbox dispatch job failed on resend: '.$e->getMessage());
        }

        return true;
    }

    private function recordAuditLog(int $tenantId, string $action, string $subjectType, int $subjectId, string $description, ?int $userId = null): void
    {
        app(AuditTrailService::class)->log(
            tenantId: $tenantId,
            action: $action,
            userId: $userId ?? auth()->id(),
            subjectType: $subjectType,
            subjectId: $subjectId,
            description: $description
        );
    }
}
