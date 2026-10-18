<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use App\Models\Store;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeviceService
{
    public function __construct(
        protected AuditTrailService $auditTrail
    ) {}

    /**
     * Membangun kueri daftar perangkat fisik yang terisolasi per tenant aktif.
     */
    public function getFilteredQuery(Request $request): Builder
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        $query = Device::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with('store')
            ->latest('id');

        if ($request->filled('store_id')) {
            $query->where('store_id', (int) $request->input('store_id'));
        }

        if ($request->filled('device_type')) {
            $query->where('device_type', (string) $request->input('device_type'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(function (Builder $q) use ($search) {
                $q->where('device_name', 'like', "%{$search}%")
                    ->orWhere('device_code', 'like', "%{$search}%")
                    ->orWhere('device_fingerprint', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    /**
     * Mendaftarkan entitas perangkat baru dan menerbitkan token pairing awal.
     */
    public function registerDevice(Store $store, array $data): Device
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        if ((int) $store->tenant_id !== (int) $tenantId) {
            throw new DomainException('Cabang tidak valid untuk tenant yang sedang aktif.');
        }

        return DB::transaction(function () use ($tenantId, $store, $data) {
            $pairingToken = strtoupper(Str::random(12)); // 12-char high-entropy alphanumeric token
            $expiresAt = Carbon::now()->addHours(24);

            $device = new Device();
            $device->tenant_id = $tenantId;
            $device->store_id = $store->id;
            $device->device_name = $data['device_name'];
            $device->device_type = $data['device_type'] ?? Device::TYPE_POS_TERMINAL;
            $device->pairing_token = $pairingToken;
            $device->pairing_token_expires_at = $expiresAt;
            $device->status = Device::STATUS_PENDING;
            $device->is_active = true;
            $device->metadata = $data['metadata'] ?? null;

            if (! empty($data['device_code'])) {
                $device->device_code = $data['device_code'];
            }

            $device->save();

            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'device.registered',
                userId: Auth::id(),
                storeId: $store->id,
                subjectType: Device::class,
                subjectId: $device->id,
                description: "Perangkat kasir '{$device->device_name}' ({$device->device_code}) didaftarkan untuk cabang '{$store->name}'.",
                newValues: [
                    'device_name' => $device->device_name,
                    'device_type' => $device->device_type,
                    'status' => $device->status,
                    'pairing_token_expires_at' => $expiresAt->toDateTimeString(),
                ]
            );

            return $device;
        });
    }

    /**
     * Menghubungkan (pair) perangkat terminal fisik menggunakan pairing token dan device fingerprint.
     *
     * @throws DomainException jika token tidak valid atau kedaluwarsa.
     */
    public function pairDevice(
        string $pairingToken,
        string $fingerprint,
        ?string $ipAddress = null,
        ?array $metadata = null
    ): Device {
        return DB::transaction(function () use ($pairingToken, $fingerprint, $ipAddress, $metadata) {
            /** @var Device|null $device */
            $device = Device::withoutGlobalScopes()
                ->where('pairing_token', trim($pairingToken))
                ->lockForUpdate()
                ->first();

            if (! $device) {
                throw new DomainException('Kode pairing tidak ditemukan atau sudah tidak valid.');
            }

            if ($device->status === Device::STATUS_REVOKED) {
                throw new DomainException('Perangkat ini telah dicabut (revoked) oleh administrator.');
            }

            if ($device->pairing_token_expires_at && $device->pairing_token_expires_at->isPast()) {
                throw new DomainException('Kode pairing telah kedaluwarsa. Silakan minta kode baru dari dashboard.');
            }

            $oldStatus = $device->status;

            $device->status = Device::STATUS_PAIRED;
            $device->device_fingerprint = $fingerprint;
            $device->pairing_token = null;
            $device->pairing_token_expires_at = null;
            $device->is_active = true;
            $device->last_seen_at = Carbon::now();
            $device->ip_address = $ipAddress;

            if ($metadata) {
                $existing = $device->metadata ?? [];
                $device->metadata = array_merge($existing, $metadata);
            }

            $device->save();

            $this->auditTrail->log(
                tenantId: (int) $device->tenant_id,
                action: 'device.paired',
                userId: Auth::id(),
                storeId: (int) $device->store_id,
                subjectType: Device::class,
                subjectId: $device->id,
                description: "Terminal fisik kasir '{$device->device_name}' ({$device->device_code}) berhasil dipasangkan (paired).",
                oldValues: ['status' => $oldStatus],
                newValues: [
                    'status' => Device::STATUS_PAIRED,
                    'device_fingerprint' => $fingerprint,
                    'ip_address' => $ipAddress,
                ]
            );

            return $device;
        });
    }

    /**
     * Menerbitkan ulang token pairing untuk perangkat yang belum terhubung atau revoked.
     */
    public function regeneratePairingToken(Device $device): Device
    {
        return DB::transaction(function () use ($device) {
            $pairingToken = strtoupper(Str::random(12));
            $expiresAt = Carbon::now()->addHours(24);

            $oldStatus = $device->status;

            $device->pairing_token = $pairingToken;
            $device->pairing_token_expires_at = $expiresAt;
            $device->status = Device::STATUS_PENDING;
            $device->device_fingerprint = null;
            $device->save();

            $this->auditTrail->log(
                tenantId: (int) $device->tenant_id,
                action: 'device.token_regenerated',
                userId: Auth::id(),
                storeId: (int) $device->store_id,
                subjectType: Device::class,
                subjectId: $device->id,
                description: "Token pairing untuk perangkat '{$device->device_name}' ({$device->device_code}) diterbitkan ulang.",
                oldValues: ['status' => $oldStatus],
                newValues: [
                    'status' => Device::STATUS_PENDING,
                    'pairing_token_expires_at' => $expiresAt->toDateTimeString(),
                ]
            );

            return $device;
        });
    }

    /**
     * Memutus sambungan (revoke) terminal kasir fisik.
     */
    public function revokeDevice(Device $device): Device
    {
        return DB::transaction(function () use ($device) {
            $oldStatus = $device->status;

            $device->status = Device::STATUS_REVOKED;
            $device->is_active = false;
            $device->device_fingerprint = null;
            $device->pairing_token = null;
            $device->pairing_token_expires_at = null;
            $device->save();

            $this->auditTrail->log(
                tenantId: (int) $device->tenant_id,
                action: 'device.revoked',
                userId: Auth::id(),
                storeId: (int) $device->store_id,
                subjectType: Device::class,
                subjectId: $device->id,
                description: "Akses terminal kasir '{$device->device_name}' ({$device->device_code}) dicabut (revoked).",
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => Device::STATUS_REVOKED, 'is_active' => false]
            );

            return $device;
        });
    }

    /**
     * Memperbarui informasi profil perangkat.
     */
    public function update(Device $device, array $data): Device
    {
        return DB::transaction(function () use ($device, $data) {
            $oldValues = $device->only(['device_name', 'device_type', 'is_active']);

            $device->update([
                'device_name' => $data['device_name'] ?? $device->device_name,
                'device_type' => $data['device_type'] ?? $device->device_type,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $device->is_active,
            ]);

            $this->auditTrail->log(
                tenantId: (int) $device->tenant_id,
                action: 'device.updated',
                userId: Auth::id(),
                storeId: (int) $device->store_id,
                subjectType: Device::class,
                subjectId: $device->id,
                description: "Data perangkat '{$device->device_name}' ({$device->device_code}) diperbarui.",
                oldValues: $oldValues,
                newValues: $device->only(['device_name', 'device_type', 'is_active'])
            );

            return $device;
        });
    }

    /**
     * Menghapus perangkat fisik dari cabang.
     */
    public function deleteDevice(Device $device): bool
    {
        return DB::transaction(function () use ($device) {
            $tenantId = (int) $device->tenant_id;
            $storeId = (int) $device->store_id;
            $deviceName = $device->device_name;
            $deviceCode = $device->device_code;
            $deviceId = (int) $device->id;

            $deleted = $device->delete();

            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'device.deleted',
                userId: Auth::id(),
                storeId: $storeId,
                subjectType: Device::class,
                subjectId: $deviceId,
                description: "Perangkat kasir '{$deviceName}' ({$deviceCode}) dihapus."
            );

            return (bool) $deleted;
        });
    }
}
