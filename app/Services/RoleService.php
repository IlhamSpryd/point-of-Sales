<?php

namespace App\Services;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Exception;

class RoleService
{
    public function getFilteredQuery($request)
    {
        $query = Role::withCount('users')->latest();
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%");
        }
        return $query;
    }

    public function exportCsv($request): StreamedResponse
    {
        $query = $this->getFilteredQuery($request);
        $fileName = 'roles_export_' . now()->format('Y-m-d_H-i-s') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Name', 'Description', 'Assigned Users']);

            $query->chunk(100, function ($roles) use ($handle) {
                foreach ($roles as $role) {
                    fputcsv($handle, [
                        $role->id,
                        $role->name,
                        $role->description,
                        $role->users_count, // Works because we injected withCount('users') in getFilteredQuery
                    ]);
                }
            });
            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    /**
     * Store a new role.
     */
    public function store(array $data): Role
    {
        return DB::transaction(function () use ($data) {
            return Role::create($data);
        });
    }

    /**
     * Update an existing role.
     */
    public function update(Role $role, array $data): Role
    {
        return DB::transaction(function () use ($role, $data) {
            $role->update($data);
            return $role;
        });
    }

    /**
     * Delete a role.
     */
    public function delete(Role $role): bool
    {
        return DB::transaction(function () use ($role) {
            if ($role->users()->count() > 0) {
                throw new Exception('Cannot delete role "' . $role->name . '" because ' . $role->users()->count() . ' user(s) are still assigned to it. Reassign them first.');
            }

            return $role->delete();
        });
    }
}
