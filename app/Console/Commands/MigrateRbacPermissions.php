<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('rbac:migrate-permissions')]
#[Description('Migrate JSON permissions to relational tables (permissions, role_permissions)')]
class MigrateRbacPermissions extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting RBAC migration...');

        $roles = DB::table('roles')->get();
        $totalRoles = $roles->count();
        $permissionsMap = [];

        DB::beginTransaction();

        try {
            foreach ($roles as $role) {
                // Decode JSON permissions if it exists
                $permissions = is_string($role->permissions) ? json_decode($role->permissions, true) : $role->permissions;
                
                if (empty($permissions) || !is_array($permissions)) {
                    continue;
                }

                foreach ($permissions as $permissionCode) {
                    // Create permission if it doesn't exist yet in our map
                    if (!isset($permissionsMap[$permissionCode])) {
                        // Check if it already exists in DB to prevent duplicates on rerun
                        $existing = DB::table('permissions')->where('permission_code', $permissionCode)->first();
                        
                        if ($existing) {
                            $permissionsMap[$permissionCode] = $existing->id;
                        } else {
                            $permissionId = DB::table('permissions')->insertGetId([
                                'permission_code' => $permissionCode,
                                'description' => 'Migrated from JSON for code: ' . $permissionCode
                            ]);
                            $permissionsMap[$permissionCode] = $permissionId;
                        }
                    }

                    // Attach to role
                    DB::table('role_permissions')->updateOrInsert(
                        [
                            'role_id' => $role->id,
                            'permission_id' => $permissionsMap[$permissionCode]
                        ]
                    );
                }
            }

            DB::commit();
            $this->info("Successfully migrated permissions for {$totalRoles} roles.");
            $this->info("Total unique permissions created/mapped: " . count($permissionsMap));
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('Failed to migrate permissions: ' . $e->getMessage());
        }
    }
}
