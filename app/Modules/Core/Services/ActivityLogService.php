<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Core\Context\TenantContext;
use App\Models\ActivityLog;
use App\Modules\Settings\Models\Store;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use RuntimeException;

/**
 * ActivityLogService — Layanan sentral pencatatan jejak audit mutasi sensitif append-only.
 *
 * Menerapkan:
 * - Append-only semantics: dilarang melakukan modifikasi atau penghapusan log audit.
 * - Resolusi otomatis tenant, store, user, dan HTTP metadata jika tidak disuplai eksplisit.
 * - Isolasi tenant yang ketat.
 */
class ActivityLogService
{
    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Catat log audit aktivitas baru secara append-only.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>|null  $metadata
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
        ?string $userAgent = null,
    ): ActivityLog {
        $ip = $ipAddress ?? Request::ip();
        $ua = $userAgent ?? (Request::userAgent() ? substr(Request::userAgent(), 0, 500) : null);

        // Resolusi store_id jika belum diisi (memenuhi syarat skema basis data store_id NOT NULL)
        $resolvedStoreId = $storeId ?? $this->tenantContext->getStoreId();
        if ($resolvedStoreId === null && $userId !== null) {
            $resolvedStoreId = (int) (DB::table('user_stores')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->orderBy('store_id')
                ->value('store_id') ?? 0) ?: null;
        }
        if ($resolvedStoreId === null) {
            $resolvedStoreId = (int) (Store::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->value('id') ?? 0) ?: null;
        }

        return ActivityLog::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'store_id' => $resolvedStoreId,
            'user_id' => $userId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $ip,
            'user_agent' => $ua,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Catat mutasi data sensitif menggunakan konteks request aktif saat ini.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>|null  $metadata
     */
    public function logSensitiveMutation(
        string $action,
        ?string $description = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
    ): ActivityLog {
        $tenantId = $this->tenantContext->requireTenantId();
        $storeId = $this->tenantContext->getStoreId();
        $userId = Auth::id() ? (int) Auth::id() : null;

        return $this->log(
            tenantId: $tenantId,
            action: $action,
            userId: $userId,
            storeId: $storeId,
            subjectType: $subjectType,
            subjectId: $subjectId,
            description: $description,
            oldValues: $oldValues,
            newValues: $newValues,
            metadata: $metadata,
        );
    }

    /**
     * Ambil histori audit log untuk tenant tertentu.
     *
     * @return Collection<int, ActivityLog>
     */
    public function getLogsForTenant(int $tenantId, int $limit = 50): Collection
    {
        return ActivityLog::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Cegah update log audit (append-only enforcement).
     *
     * @param array<string, mixed> $attributes
     */
    public function updateLog(int $logId, array $attributes): never
    {
        throw new RuntimeException('Activity logs are append-only and cannot be modified.');
    }

    /**
     * Cegah penghapusan log audit (append-only enforcement).
     */
    public function deleteLog(int $logId): never
    {
        throw new RuntimeException('Activity logs are append-only and cannot be deleted.');
    }
}
