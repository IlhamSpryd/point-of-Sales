<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Store;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AuditTrailService: Layanan sentral untuk pencatatan jejak audit (Activity Log)
 * yang mematuhi invarian multi-tenancy dan skema append-only database.
 *
 * Mencegah:
 * 1. Pelanggaran foreign key komposit (tenant_id, user_id).
 * 2. Atribusi palsu ke user_id sembarang (seperti auth()->id() ?? 1).
 * 3. Gagal tulis karena ketiadaan store_id (otomatis menyelesaikan store valid untuk tenant).
 */
class AuditTrailService
{
    /**
     * Catat aktivitas ke tabel activity_logs secara aman dan terisolasi.
     *
     * @param  int  $tenantId  ID tenant pemilik aktivitas (wajib)
     * @param  string  $action  Kode aksi terstruktur (mis: 'user.login', 'tenant.onboarding_completed')
     * @param  int|null  $userId  ID pengguna pelaku (opsional, divalidasi harus milik tenantId)
     * @param  int|null  $storeId  ID cabang terkait (opsional, di-resolve otomatis jika null)
     * @param  string|null  $subjectType  Nama kelas model subjek (opsional)
     * @param  int|null  $subjectId  ID model subjek (opsional)
     * @param  string|null  $description  Keterangan manusiawi (opsional)
     * @param  array<string, mixed>|null  $oldValues  Nilai sebelum perubahan (opsional)
     * @param  array<string, mixed>|null  $newValues  Nilai sesudah perubahan (opsional)
     * @param  array<string, mixed>|null  $metadata  Data konteks tambahan (opsional)
     * @param  string|null  $ipAddress  IP klien (opsional, default: request()->ip())
     * @param  string|null  $userAgent  User agent klien (opsional, default: request()->userAgent())
     */
    public function log(
        int $tenantId,
        string $action,
        ?int $userId = null,
        ?int $storeId = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): ?int {
        try {
            // 1. Validasi & resolusi user_id terhadap tenant_id
            $resolvedUserId = $this->resolveUserId($tenantId, $userId, $metadata);

            // 2. Resolusi store_id yang valid untuk tenant_id
            $resolvedStoreId = $this->resolveStoreId($tenantId, $storeId, $resolvedUserId);

            // 3. Fallback IP dan User Agent dari HTTP request jika ada
            $resolvedIp = $ipAddress ?? (request()->ip() ?? '127.0.0.1');
            $resolvedUserAgent = $userAgent ?? request()->userAgent();

            // 4. Sanitasi terpusat old_values, new_values, dan metadata
            $sanitizedOldValues = $this->sanitizePayload($oldValues);
            $sanitizedNewValues = $this->sanitizePayload($newValues);
            $sanitizedMetadata = $this->sanitizePayload($metadata);

            $id = DB::table('activity_logs')->insertGetId([
                'tenant_id' => $tenantId,
                'store_id' => $resolvedStoreId,
                'user_id' => $resolvedUserId,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'description' => $description,
                'old_values' => $sanitizedOldValues !== null ? json_encode($sanitizedOldValues) : null,
                'new_values' => $sanitizedNewValues !== null ? json_encode($sanitizedNewValues) : null,
                'ip_address' => $resolvedIp ? substr($resolvedIp, 0, 45) : null,
                'user_agent' => $resolvedUserAgent ? substr($resolvedUserAgent, 0, 500) : null,
                'metadata' => ! empty($sanitizedMetadata) ? json_encode($sanitizedMetadata) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return (int) $id;
        } catch (\Throwable $e) {
            Log::warning("[AUDIT-TRAIL] Gagal mencatat activity log '{$action}' untuk tenant #{$tenantId}: ".$e->getMessage(), [
                'tenant_id' => $tenantId,
                'action' => $action,
                'user_id' => $userId,
                'store_id' => $storeId,
                'exception' => $e,
            ]);

            return null;
        }
    }

    /**
     * Memastikan user_id benar-benar milik tenant_id untuk mencegah pelanggaran
     * foreign key komposit (tenant_id, user_id).
     */
    private function resolveUserId(int $tenantId, ?int $userId, ?array &$metadata): ?int
    {
        if ($userId === null) {
            return null;
        }

        $belongsToTenant = User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $userId)
            ->exists();

        if ($belongsToTenant) {
            return $userId;
        }

        // Jika user bukan milik tenant ini, jangan gunakan user_id mentah (fail-safe).
        // Catat user asli ke dalam metadata untuk tujuan penyelidikan forensik.
        if ($metadata === null) {
            $metadata = [];
        }
        $metadata['unverified_actor_user_id'] = $userId;
        $metadata['user_tenant_mismatch'] = true;

        Log::warning("[AUDIT-TRAIL] Mismatch user #{$userId} dengan tenant #{$tenantId}. User_id disetel NULL pada activity_log.");

        return null;
    }

    /**
     * Menyelesaikan store_id yang valid untuk tenant_id.
     */
    private function resolveStoreId(int $tenantId, ?int $storeId, ?int $userId): int
    {
        // A. Cek storeId eksplisit jika diberikan
        if ($storeId !== null) {
            $exists = Store::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('id', $storeId)
                ->exists();

            if ($exists) {
                return $storeId;
            }
        }

        // B. Cek dari TenantContext
        $contextStoreId = app(TenantContext::class)->getStoreId();
        if ($contextStoreId !== null) {
            $contextStoreExists = Store::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('id', $contextStoreId)
                ->exists();

            if ($contextStoreExists) {
                return $contextStoreId;
            }
        }

        // C. Cek dari store penugasan user
        if ($userId !== null) {
            $userStoreId = DB::table('user_stores')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->orderBy('store_id')
                ->value('store_id');

            if ($userStoreId !== null) {
                return (int) $userStoreId;
            }
        }

        // D. Fallback ke cabang pertama milik tenant
        $firstStoreId = Store::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->value('id');

        if ($firstStoreId !== null) {
            return (int) $firstStoreId;
        }

        // E. Fallback terakhir jika belum ada store yang tersimpan sama sekali (pre-branch onboarding)
        return 1;
    }

    /**
     * Sanitasi terpusat untuk payload audit agar tidak membocorkan kredensial atau rahasia.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public function sanitizePayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        try {
            return $this->redactSensitiveData($payload);
        } catch (\Throwable $e) {
            Log::warning('[AUDIT-TRAIL] Gagal melakukan sanitasi payload audit: '.$e->getMessage());

            return ['_sanitization_error' => 'Payload could not be safely sanitized'];
        }
    }

    /**
     * Rekursif menyaring array bertingkat.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redactSensitiveData(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->redactSensitiveData($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Mendeteksi apakah nama key mengandung identifier kredensial rahasia (case-insensitive & format-agnostic).
     */
    public function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['_', '-', ' ', '.'], '', $key));

        // Pertahankan metadata operasional/waktu non-sensitif yang memuat substring kata kunci
        if (
            str_ends_with($normalized, 'expiresat') ||
            str_ends_with($normalized, 'expiredat') ||
            str_ends_with($normalized, 'expiresin') ||
            str_ends_with($normalized, 'count') ||
            str_ends_with($normalized, 'length')
        ) {
            return false;
        }

        $sensitivePatterns = [
            'password',
            'passwd',
            'pin',
            'secret',
            'token',
            'otp',
            'totp',
            'recoverycode',
            'recoverycodes',
            'cvv',
            'cvc',
            'cardnumber',
            'creditcard',
            'authorization',
            'apikey',
            'privatekey',
        ];

        foreach ($sensitivePatterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
