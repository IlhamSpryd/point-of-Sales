<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Exception;
use Illuminate\Support\Facades\Auth;

class UserService
{
    public function getFilteredQuery($request)
    {
        $query = User::with('role')->latest();
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone_number', 'like', "%{$search}%");
            });
        }
        return $query;
    }

    public function exportCsv($request): StreamedResponse
    {
        $query = $this->getFilteredQuery($request);
        $fileName = 'users_export_' . now()->format('Y-m-d_H-i-s') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Name', 'Email', 'Phone Number', 'Role', 'Status', 'Joined Date']);

            $query->chunk(100, function ($users) use ($handle) {
                foreach ($users as $user) {
                    fputcsv($handle, [
                        $user->id,
                        $user->name,
                        $user->email,
                        $user->phone_number,
                        $user->role ? $user->role->name : 'Unassigned',
                        $user->is_active ? 'Active' : 'Inactive',
                        $user->created_at ? $user->created_at->format('Y-m-d H:i') : ''
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
     * Store a new user.
     */
    public function store(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $data['is_active'] = isset($data['is_active']) ? (bool) $data['is_active'] : false;
            return User::create($data);
        });
    }

    /**
     * Update an existing user.
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $data['is_active'] = isset($data['is_active']) ? (bool) $data['is_active'] : false;

            if (empty($data['password'])) {
                unset($data['password']);
            }

            $user->update($data);

            return $user;
        });
    }

    /**
     * Delete a user.
     */
    public function delete(User $user): bool
    {
        if ($user->id === Auth::id()) {
            throw new Exception('You cannot delete yourself.');
        }

        return DB::transaction(function () use ($user) {
            return $user->delete();
        });
    }
}
