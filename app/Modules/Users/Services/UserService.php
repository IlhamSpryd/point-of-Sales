<?php

namespace App\Modules\Users\Services;

use App\Exports\UsersExport;
use App\Models\Shift;
use App\Models\User;
use App\Services\Context\TenantContext;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * UserService adalah otak pemrosesan di balik layar yang berkaitan erat
 * dengan akun staf pengguna (Penyaringan dan Keamanan).
 */
class UserService
{
    /**
     * @return Builder<User>
     */
    public function getFilteredQuery(Request $request): Builder
    {
        // User TIDAK punya global scope tenant (BD-7: login menemukan user hanya via email),
        // jadi scoping dilakukan secara eksplisit di sini.
        $query = User::query()
            ->where('tenant_id', app(TenantContext::class)->requireTenantId())
            ->with('role')->latest('id');
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    /**
     * Mencetak daftar Akun secara format Excel.
     */
    public function exportCsv(Request $request): BinaryFileResponse
    {
        $query = $this->getFilteredQuery($request);
        $fileName = 'users_export_'.now()->format('Y-m-d_H-i-s').'.xlsx';

        return Excel::download(new UsersExport($query), $fileName);
    }

    /**
     * Mendaftarkan Pengguna baru.
     *
     * @param  array<string, mixed>  $data
     */
    public function store(array $data): User
    {
        if (isset($data['password'])) {
            $data['password_hash'] = $data['password'];
            unset($data['password']);
        }
        if (isset($data['pin_code'])) {
            $data['pin_hash'] = $data['pin_code'];
            unset($data['pin_code']);
        }

        $storeIds = $data['store_ids'] ?? null;
        unset($data['store_ids']);

        return DB::transaction(function () use ($data, $storeIds) {
            $tenantId = (int) app(TenantContext::class)->requireTenantId();
            $user = new User($data);
            $user->tenant_id = $tenantId;
            $user->save();

            $this->syncUserStores($user, is_array($storeIds) ? $storeIds : null);

            // Link to existing employee if email matches and not yet linked,
            // or auto-provision baseline Employee profile to prevent orphaned user accounts
            $tenantId = (int) $user->tenant_id;
            $existingEmployee = \App\Modules\HR\Models\Employee::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where(function ($q) use ($user) {
                    $q->where('email', $user->email)
                        ->orWhere('user_id', $user->id);
                })
                ->first();

            if ($existingEmployee) {
                if (! $existingEmployee->user_id) {
                    $existingEmployee->user_id = $user->id;
                    $existingEmployee->save();
                }
            } else {
                $defaultDept = \App\Modules\HR\Models\Department::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->first();
                if (! $defaultDept) {
                    $defaultDept = \App\Modules\HR\Models\Department::create([
                        'tenant_id' => $tenantId,
                        'department_code' => 'OPS',
                        'name' => 'Store Operations',
                        'description' => 'Default operations department',
                        'is_active' => true,
                    ]);
                }

                $roleName = $user->role ? $user->role->name : 'Staff';
                $titleCode = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $roleName) ?: 'STAFF', 0, 16));
                $jobTitle = \App\Modules\HR\Models\JobTitle::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('title_code', $titleCode)
                    ->first();
                if (! $jobTitle) {
                    $jobTitle = \App\Modules\HR\Models\JobTitle::create([
                        'tenant_id' => $tenantId,
                        'title_code' => $titleCode,
                        'name' => $user->role ? ($user->role->display_name ?? $roleName) : 'General Staff',
                        'department_id' => $defaultDept->id,
                        'grade_level' => 1,
                        'is_active' => true,
                    ]);
                }

                $empNumber = ! empty($user->employee_id) ? $user->employee_id : \App\Services\EmployeeIdGenerator::next($tenantId);

                \App\Modules\HR\Models\Employee::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $user->id,
                    'employee_number' => $empNumber,
                    'full_name' => $user->name,
                    'email' => $user->email,
                    'phone_number' => $user->phone_number ?: '080000000000',
                    'gender' => \App\Modules\HR\Enums\GenderEnum::MALE,
                    'department_id' => $defaultDept->id,
                    'job_title_id' => $jobTitle->id,
                    'employment_type' => \App\Modules\HR\Enums\EmploymentTypeEnum::PERMANENT,
                    'employment_status' => $user->is_active ? \App\Modules\HR\Enums\EmploymentStatusEnum::ACTIVE : \App\Modules\HR\Enums\EmploymentStatusEnum::ARCHIVED,
                    'hire_date' => $user->join_date ? $user->join_date->toDateString() : now()->toDateString(),
                ]);
            }

            return $user;
        });
    }

    /**
     * Mengatur ulang rincian spesifik dari Pengguna.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            // Cegah penonaktifan atau penangguhan kasir jika masih memiliki shift aktif
            $isBeingDeactivated = (isset($data['is_active']) && ! $data['is_active'])
                || (isset($data['status']) && in_array($data['status'], ['suspended', 'inactive'], true));

            if ($isBeingDeactivated) {
                $hasOpenShift = Shift::where('user_id', $user->id)
                    ->where('status', 'open')
                    ->exists();

                if ($hasOpenShift) {
                    throw new \DomainException('Cannot deactivate a cashier with an open active shift. Please close the active shift first.');
                }
            }

            if (empty($data['password'])) {
                unset($data['password']);
            } else {
                $data['password_hash'] = $data['password'];
                unset($data['password']);
            }

            if (empty($data['pin_code'])) {
                unset($data['pin_code']);
            } else {
                $data['pin_hash'] = $data['pin_code'];
                unset($data['pin_code']);
            }

            $hasStoreIdsInput = array_key_exists('store_ids', $data);
            $storeIds = $data['store_ids'] ?? [];
            unset($data['store_ids']);

            $user->update($data);

            if ($hasStoreIdsInput && is_array($storeIds)) {
                $this->syncUserStores($user, $storeIds);
            }

            // Invalidate active sessions if credentials or roles change
            if (isset($data['password_hash']) || (isset($data['is_active']) && ! $data['is_active']) || isset($data['role_id']) || isset($data['status'])) {
                try {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                } catch (\Throwable) {
                }
            }

            return $user;
        });
    }

    /**
     * Delete user. Users cannot delete their own account.
     */
    public function delete(User $user): bool
    {
        if ($user->id === Auth::id()) {
            throw new Exception('You cannot delete your own account.');
        }

        $hasOpenShift = Shift::where('user_id', $user->id)
            ->where('status', 'open')
            ->exists();

        if ($hasOpenShift) {
            throw new \DomainException('Cannot delete a cashier with an open active shift. Please close the active shift first.');
        }

        if ($user->orders()->exists()) {
            throw new Exception('This user has associated transactions or orders and cannot be deleted.');
        }

        return DB::transaction(function () use ($user) {
            try {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            } catch (\Throwable) {
            }

            return (bool) $user->delete();
        });
    }

    /**
     * Revoke store access for a user.
     * Rejected if user has an open active shift at that store.
     */
    public function revokeStoreAccess(User $user, int $storeId): void
    {
        $hasOpenShift = Shift::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('store_id', $storeId)
            ->where('status', 'open')
            ->exists();

        if ($hasOpenShift) {
            throw new \DomainException('Cannot revoke store branch access for a user with an open active shift at this branch. Please close the active shift first.');
        }

        DB::table('user_stores')
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('store_id', $storeId)
            ->delete();
    }

    /**
     * Sinkronisasi penugasan multi-cabang (`user_stores`) dengan perlindungan shift aktif.
     *
     * @param  array<int, int|string>|null  $storeIds
     */
    public function syncUserStores(User $user, ?array $storeIds): void
    {
        $tenantId = (int) $user->tenant_id;

        if ($storeIds === null) {
            $activeStoreId = app(TenantContext::class)->getStoreId();
            $storeIds = $activeStoreId ? [(int) $activeStoreId] : [];
        }

        $validStoreIds = \App\Models\Store::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', array_map('intval', $storeIds))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $currentStoreIds = DB::table('user_stores')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->pluck('store_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $removedStoreIds = array_diff($currentStoreIds, $validStoreIds);
        foreach ($removedStoreIds as $removedStoreId) {
            $this->revokeStoreAccess($user, (int) $removedStoreId);
        }

        $toInsert = array_diff($validStoreIds, $currentStoreIds);
        foreach ($toInsert as $storeId) {
            DB::table('user_stores')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'store_id' => (int) $storeId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
