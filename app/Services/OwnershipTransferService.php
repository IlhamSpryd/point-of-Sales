<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\OwnershipTransferException;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OwnershipTransferService
{
    /**
     * Mentransfer legal ownership tenant secara atomik dan menegakkan seluruh
     * invarian arsitektur multi-tenancy.
     *
     * Invarian Kunci:
     * 1. users.tenant_id tetap SSOT identitas binding akun ke tenant.
     * 2. tenants.owner_user_id semata-mata referensi kepemilikan legal.
     * 3. users.role_id menentukan otorisasi operasional.
     * 4. Tepat 1 user aktif dengan role kanonikal ROL-OWNER per tenant.
     * 5. Atomicity & Fail-Closed: seluruh mutasi dan audit trail berada dalam transaksi yang sama.
     *
     * @param Tenant $tenant Target tenant yang kepemilikannya akan ditransfer
     * @param User $actor Aktor yang mengeksekusi transfer (Legal Owner aktif atau Super-Admin terotorisasi)
     * @param User $newOwner Kandidat pemilik baru (harus aktif, tidak terhapus, terikat pada tenant)
     * @param string|null $reason Alasan transfer untuk audit trail
     * @param array $options Opsi tambahan:
     *                       - 'store_id' (int|null): Store eksplisit untuk atribusi audit log jika diperlukan
     *                       - 'demote_to_role_code' (string): Kode role untuk demosi old owner (default: ROL-MANAGER)
     *                       - 'reconcile_legacy_owners' (bool): Jika true, mendemosi anomali user lama ber-role ROL-OWNER
     *                       - 'idempotent' (bool): Jika true, transfer ke owner yang sama dianggap no-op sukses
     *                       - 'simulate_audit_failure' (bool): Untuk uji integritas rollback audit trail
     * @return void
     * @throws OwnershipTransferException
     */
    public function transferOwnership(
        Tenant $tenant,
        User $actor,
        User $newOwner,
        ?string $reason = null,
        array $options = []
    ): void {
        // 1. Validasi Kesiapan Skema Fisik (Fail-Closed)
        if (! Schema::hasColumn('tenants', 'owner_user_id')) {
            throw new OwnershipTransferException(
                'Kolom tenants.owner_user_id belum tersedia pada skema database. Migrasi DDL ownership diperlukan.'
            );
        }

        // 2. Pre-transaction candidate cross-tenant sanity check
        if ((int) $newOwner->tenant_id !== (int) $tenant->id) {
            throw new OwnershipTransferException(
                "Kandidat pemilik baru tidak valid atau berasal dari tenant lain (cross-tenant violation: target tenant {$tenant->id}, user tenant {$newOwner->tenant_id})."
            );
        }

        // 3. Eksekusi Atomik dengan Serialized Row Locking
        DB::transaction(function () use ($tenant, $actor, $newOwner, $reason, $options) {
            // Urutan Locking Deterministik:
            // 1. Tenant row (mengunci resource agregat teratas, menserialisasi transfer конкурен)
            /** @var Tenant $lockedTenant */
            $lockedTenant = Tenant::where('id', $tenant->id)->lockForUpdate()->firstOrFail();

            // 2. Actor row (mencegah perubahan status/role aktor selama transfer berjalan)
            /** @var User|null $lockedActor */
            $lockedActor = User::withTrashed()
                ->where('id', $actor->id)
                ->lockForUpdate()
                ->first();
            if (! $lockedActor) {
                throw new OwnershipTransferException("Aktor (ID: {$actor->id}) tidak ditemukan dalam database.");
            }

            // 3. Candidate New Owner row (termasuk soft-deleted untuk verifikasi integritas status)
            /** @var User|null $lockedNewOwner */
            $lockedNewOwner = User::withTrashed()
                ->where('id', $newOwner->id)
                ->where('tenant_id', $lockedTenant->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedNewOwner) {
                throw new OwnershipTransferException(
                    "Kandidat pemilik baru (ID: {$newOwner->id}) tidak ditemukan pada tenant {$lockedTenant->id}."
                );
            }

            // 4. Old Owner row (jika ada dan terikat pada tenant yang sama)
            $oldOwnerId = $lockedTenant->owner_user_id !== null ? (int) $lockedTenant->owner_user_id : null;
            $lockedOldOwner = null;
            if ($oldOwnerId !== null) {
                $lockedOldOwner = User::where('id', $oldOwnerId)
                    ->where('tenant_id', $lockedTenant->id)
                    ->lockForUpdate()
                    ->first();
            }

            // 5. Evaluasi Ulang Otorisasi Aktor Pasca-Lock (Tolak Stale State & Revoked Permissions)
            $this->validateActorAuthorization($lockedTenant, $lockedActor);

            // 6. Validasi Integritas Kandidat New Owner Pasca-Lock
            if (! $lockedNewOwner->is_active || $lockedNewOwner->deleted_at !== null) {
                throw new OwnershipTransferException(
                    "Kandidat pemilik baru harus berstatus aktif dan belum dihapus (is_active: {$lockedNewOwner->is_active}, deleted_at: {$lockedNewOwner->deleted_at})."
                );
            }

            // 7. Kebijakan Transfer ke Owner yang Sama (Idempotent vs Explicit Rejection)
            if ($oldOwnerId !== null && $oldOwnerId === (int) $lockedNewOwner->id) {
                if (! empty($options['idempotent']) && $options['idempotent'] === true) {
                    return; // Idempotent no-op
                }
                throw new OwnershipTransferException(
                    "Kandidat sudah merupakan legal owner aktif untuk tenant ini."
                );
            }

            // 8. Resolusi dan Validasi Role Kanonikal ROL-OWNER
            $ownerRole = Role::withoutTenantScope()
                ->where('tenant_id', $lockedTenant->id)
                ->where('role_code', 'ROL-OWNER')
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->first();

            if (! $ownerRole) {
                throw new OwnershipTransferException(
                    "Role kanonikal ROL-OWNER tidak ditemukan atau tidak aktif pada tenant {$lockedTenant->id}."
                );
            }

            // 9. Resolusi dan Validasi Role Demosi untuk Owner Lama / Rekonsiliasi
            $demoteRoleCode = $options['demote_to_role_code'] ?? 'ROL-MANAGER';
            if ($demoteRoleCode === 'ROL-OWNER') {
                throw new OwnershipTransferException('Role target demosi tidak boleh ROL-OWNER.');
            }

            $demoteRole = Role::withoutTenantScope()
                ->where('tenant_id', $lockedTenant->id)
                ->where('role_code', $demoteRoleCode)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->first();

            if ($lockedOldOwner !== null && (int) $lockedOldOwner->id !== (int) $lockedNewOwner->id) {
                if (! $demoteRole) {
                    throw new OwnershipTransferException(
                        "Role target demosi {$demoteRoleCode} tidak ditemukan atau tidak aktif pada tenant {$lockedTenant->id}."
                    );
                }
            }

            // Struktur pelacak mutasi role untuk audit trail
            $roleTransitions = [];

            // 10. Penegakan Invarian "Tepat Satu ROL-OWNER" terhadap Anomali Legacy
            $legacyOtherOwners = User::where('tenant_id', $lockedTenant->id)
                ->where('role_id', $ownerRole->id)
                ->where('id', '!=', $lockedNewOwner->id)
                ->whereNull('deleted_at')
                ->get();

            if ($legacyOtherOwners->isNotEmpty()) {
                $hasUnrecognizedOwners = $legacyOtherOwners->contains(function (User $u) use ($oldOwnerId) {
                    return $oldOwnerId === null || (int) $u->id !== $oldOwnerId;
                });

                if ($hasUnrecognizedOwners && empty($options['reconcile_legacy_owners'])) {
                    throw new OwnershipTransferException(
                        "Inkonsistensi terdeteksi: Terdapat {$legacyOtherOwners->count()} user lain dengan role ROL-OWNER. Rekonsiliasi peran diperlukan sebelum transfer."
                    );
                }

                if (! $demoteRole) {
                    throw new OwnershipTransferException(
                        "Role target demosi {$demoteRoleCode} tidak ditemukan untuk menyelesaikan anomali peran ROL-OWNER."
                    );
                }

                foreach ($legacyOtherOwners as $legacyOwnerUser) {
                    $legacyOwnerUser->role_id = $demoteRole->id;
                    $legacyOwnerUser->save();

                    $roleTransitions[] = [
                        'user_id' => $legacyOwnerUser->id,
                        'from_role' => 'ROL-OWNER',
                        'to_role' => $demoteRole->role_code,
                        'type' => ((int) $legacyOwnerUser->id === $oldOwnerId) ? 'previous_owner_demotion' : 'legacy_anomaly_reconciliation',
                    ];
                }
            } elseif ($lockedOldOwner !== null && (int) $lockedOldOwner->id !== (int) $lockedNewOwner->id) {
                $lockedOldOwner->role_id = $demoteRole->id;
                $lockedOldOwner->save();

                $roleTransitions[] = [
                    'user_id' => $lockedOldOwner->id,
                    'from_role' => 'ROL-OWNER',
                    'to_role' => $demoteRole->role_code,
                    'type' => 'previous_owner_demotion',
                ];
            }

            // 11. Promosi New Owner ke Role ROL-OWNER
            $previousNewOwnerRole = $lockedNewOwner->role_id
                ? Role::withoutTenantScope()->where('id', $lockedNewOwner->role_id)->value('role_code')
                : null;

            $lockedNewOwner->role_id = $ownerRole->id;
            $lockedNewOwner->save();

            $roleTransitions[] = [
                'user_id' => $lockedNewOwner->id,
                'from_role' => $previousNewOwnerRole ?? 'NONE',
                'to_role' => 'ROL-OWNER',
                'type' => 'new_owner_promotion',
            ];

            // 12. Mutasi Status Legal Ownership pada Tabel Tenants
            $lockedTenant->owner_user_id = $lockedNewOwner->id;
            $lockedTenant->save();

            // 13. Verifikasi Post-Condition Invarian: Tepat 1 User Aktif dengan ROL-OWNER
            $finalOwnersCount = User::where('tenant_id', $lockedTenant->id)
                ->where('role_id', $ownerRole->id)
                ->whereNull('deleted_at')
                ->count();

            if ($finalOwnersCount !== 1) {
                throw new OwnershipTransferException(
                    "Pelanggaran invariant post-condition: Ditemukan {$finalOwnersCount} pengguna aktif dengan role ROL-OWNER setelah transfer (seharusnya tepat 1)."
                );
            }

            // 14. Pencatatan Audit Log Wajib via ActivityLog (Atomik & Terikat)
            $this->recordAuditTrail(
                $lockedTenant,
                $lockedActor,
                $lockedNewOwner,
                $oldOwnerId,
                $reason,
                $roleTransitions,
                $options
            );
        });
    }

    /**
     * Memvalidasi otorisasi aktor berdasarkan state aktual database yang terkunci.
     * Menggunakan keadaan database terpercaya (fresh & locked), bukan in-memory stale.
     *
     * @param Tenant $lockedTenant
     * @param User $lockedActor
     * @return void
     * @throws OwnershipTransferException
     */
    private function validateActorAuthorization(Tenant $lockedTenant, User $lockedActor): void
    {
        // 1. Verifikasi Keaktifan & Integritas Akun Aktor
        if (! $lockedActor->is_active || $lockedActor->deleted_at !== null) {
            throw new OwnershipTransferException('Aktor tidak valid, nonaktif, atau telah dihapus.');
        }

        // 2. Resolusi Role Aktor secara Fail-Closed dari Database
        $actorRole = $lockedActor->role_id
            ? Role::withoutTenantScope()->where('id', $lockedActor->role_id)->whereNull('deleted_at')->first()
            : null;

        if (! $actorRole || ! $actorRole->is_active) {
            throw new OwnershipTransferException('Role aktor tidak valid, tidak aktif, atau telah dihapus.');
        }

        $roleName = strtolower((string) $actorRole->name);
        $roleCode = (string) $actorRole->role_code;

        // 3. Deteksi Identitas Role Super-Admin
        $isSuperAdminRole = in_array($roleName, ['superadmin', 'super admin'], true)
            || in_array($roleCode, ['ROL-SUPERADMIN', 'ROL-SUPERAD'], true);

        // Super-Admin terotorisasi HANYA jika memiliki izin eksplisit pada database
        $isSuperAdminAuthorized = $isSuperAdminRole && (
            $actorRole->hasPermission('tenant.transfer_ownership')
            || $actorRole->hasPermission('*')
        );

        // Jika aktor memiliki role Super-Admin tetapi permission dicabut / tidak memadai: tolak fail-closed
        if ($isSuperAdminRole && ! $isSuperAdminAuthorized) {
            throw new OwnershipTransferException(
                'Super-Admin tidak memiliki otorisasi untuk mentransfer kepemilikan tenant.'
            );
        }

        $currentOwnerId = $lockedTenant->owner_user_id !== null ? (int) $lockedTenant->owner_user_id : null;

        // Skenario 1: Tenant tanpa legal owner (owner_user_id IS NULL)
        if ($currentOwnerId === null) {
            if (! $isSuperAdminAuthorized) {
                throw new OwnershipTransferException(
                    'Tenant tanpa legal owner hanya dapat direkonsiliasi oleh Super-Admin terotorisasi.'
                );
            }

            return; // Authorized Super-Admin diizinkan
        }

        // Skenario 2: Tenant sudah memiliki legal owner
        // Aktor harus merupakan legal owner sah saat ini (pada tenant yang sama) ATAU Super-Admin terotorisasi
        $isCurrentLegalOwner = ($currentOwnerId === (int) $lockedActor->id)
            && ((int) $lockedActor->tenant_id === (int) $lockedTenant->id);

        if (! $isCurrentLegalOwner && ! $isSuperAdminAuthorized) {
            throw new OwnershipTransferException(
                'Aktor tidak terotorisasi: Hanya legal owner saat ini atau Super-Admin terotorisasi yang dapat mentransfer kepemilikan.'
            );
        }

        // 4. Verifikasi Pemilik Legal Saat Ini Tidak Mengalami Pencabutan Permission
        if ($isCurrentLegalOwner && ! $isSuperAdminAuthorized) {
            $hasOwnerPermission = $actorRole->hasPermission('tenant.transfer_ownership')
                || $actorRole->hasPermission('*');

            if (! $hasOwnerPermission) {
                throw new OwnershipTransferException(
                    'Aktor tidak terotorisasi: Permission transfer kepemilikan telah dicabut dari role aktor.'
                );
            }
        }
    }

    /**
     * Mencatat entri audit trail ke activity_logs yang terlindungi trigger.
     * Mematuhi skema fisik store_id NOT NULL tanpa mengasumsikan representasi semantik sewenang-wenang.
     *
     * @param Tenant $tenant
     * @param User $actor
     * @param User $newOwner
     * @param int|null $oldOwnerId
     * @param string|null $reason
     * @param array $roleTransitions
     * @param array $options
     * @return void
     * @throws OwnershipTransferException
     */
    private function recordAuditTrail(
        Tenant $tenant,
        User $actor,
        User $newOwner,
        ?int $oldOwnerId,
        ?string $reason,
        array $roleTransitions,
        array $options = []
    ): void {
        if (! empty($options['simulate_audit_failure'])) {
            throw new OwnershipTransferException('Simulated Audit Ledger Failure: Disk full or trigger violation.');
        }

        $actorRole = $actor->role_id
            ? Role::withoutTenantScope()->where('id', $actor->role_id)->first()
            : null;

        // Resolusi store_id berbasis aturan kanonikal AssignsStore:
        $resolvedStoreId = null;
        $storeResolutionStrategy = 'none';

        if (! empty($options['store_id'])) {
            $explicitStore = Store::where('tenant_id', $tenant->id)->where('id', $options['store_id'])->first();
            if (! $explicitStore) {
                throw new OwnershipTransferException("store_id {$options['store_id']} tidak valid untuk tenant {$tenant->id}.");
            }
            $resolvedStoreId = $explicitStore->id;
            $storeResolutionStrategy = 'explicit_option';
        } elseif (app(TenantContext::class)->getStoreId() !== null) {
            $contextStoreId = app(TenantContext::class)->getStoreId();
            $contextStore = Store::where('tenant_id', $tenant->id)->where('id', $contextStoreId)->first();
            if ($contextStore) {
                $resolvedStoreId = $contextStore->id;
                $storeResolutionStrategy = 'tenant_context';
            }
        }

        if ($resolvedStoreId === null) {
            $actorStoreId = $actor->stores()->where('stores.tenant_id', $tenant->id)->orderBy('stores.id')->value('stores.id');
            if ($actorStoreId !== null) {
                $resolvedStoreId = (int) $actorStoreId;
                $storeResolutionStrategy = 'actor_store_assignment';
            }
        }

        if ($resolvedStoreId === null) {
            $primaryStore = Store::where('tenant_id', $tenant->id)->orderBy('id')->first();
            if ($primaryStore) {
                $resolvedStoreId = $primaryStore->id;
                $storeResolutionStrategy = 'tenant_primary_store_fallback';
            }
        }

        if ($resolvedStoreId === null) {
            throw new OwnershipTransferException(
                "Tidak dapat mencatat audit log: Tenant {$tenant->id} belum memiliki store. Skema tabel activity_logs mewajibkan referensi foreign key store_id NOT NULL yang valid."
            );
        }

        $auditInserted = DB::table('activity_logs')->insert([
            'tenant_id' => $tenant->id,
            'store_id' => $resolvedStoreId,
            'user_id' => $actor->id,
            'action' => 'tenant.ownership_transferred',
            'subject_type' => Tenant::class,
            'subject_id' => $tenant->id,
            'description' => "Legal ownership tenant {$tenant->id} ditransfer dari " . ($oldOwnerId !== null ? "User {$oldOwnerId}" : 'NONE') . " ke User {$newOwner->id}.",
            'old_values' => json_encode(['owner_user_id' => $oldOwnerId]),
            'new_values' => json_encode(['owner_user_id' => $newOwner->id]),
            'metadata' => json_encode([
                'reason' => $reason,
                'actor_id' => $actor->id,
                'actor_role' => $actorRole?->role_code ?? $actorRole?->name,
                'old_owner_id' => $oldOwnerId,
                'new_owner_id' => $newOwner->id,
                'role_transitions' => $roleTransitions,
                'is_tenant_level_action' => true,
                'store_resolution_strategy' => $storeResolutionStrategy,
                'transferred_at' => now()->toIso8601String(),
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! $auditInserted) {
            throw new OwnershipTransferException('Gagal mencatat audit log transfer kepemilikan.');
        }
    }
}
