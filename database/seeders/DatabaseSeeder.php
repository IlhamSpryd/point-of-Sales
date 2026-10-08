<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RoleService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Seed Roles untuk tenant pertama (peran kini per tenant)
        $firstTenant = Tenant::firstOrCreate(['name' => 'Default Tenant']);
        app(RoleService::class)->provisionDefaultRoles($firstTenant);

        $firstTenantId = $firstTenant->id;

        // 2. Buat akun default Owner
        $adminRole = Role::where('name', 'Owner')->first();
        User::updateOrCreate(
            ['email' => 'admin@pos.test'],
            [
                'name' => 'Owner',
                'password' => Hash::make('12345678'),
                'role_id' => $adminRole->id,
                'tenant_id' => $firstTenantId,
            ]
        );
        // 3. Buat akun Kasir untuk demo
        $kasirRole = Role::where('name', 'Kasir')->first();
        User::updateOrCreate(
            ['email' => 'kasir@pos.test'],
            [
                'name' => 'Kasir Demo',
                'password' => Hash::make('12345678'),
                'role_id' => $kasirRole->id,
                'tenant_id' => $firstTenantId,
            ]
        );

        // 4. Buat akun Manager untuk demo
        $pimpinanRole = Role::where('name', 'Manager')->first();
        User::updateOrCreate(
            ['email' => 'manager@pos.test'],
            [
                'name' => 'Manager Demo',
                'password' => Hash::make('12345678'),
                'role_id' => $pimpinanRole->id,
                'tenant_id' => $firstTenantId,
            ]
        );

        // 5. Akun sistem khusus atribusi transaksi self-order pelanggan (BUKAN untuk login manual).
        // Dipakai sebagai "kasir virtual" agar user_id di tabel orders tidak pernah null,
        // menjaga konsistensi audit trail sesuai aturan proyek ini.
        User::updateOrCreate(
            ['email' => config('pos.self_order_system_email', 'selforder@system.local')],
            [
                'name' => 'Self-Order Kiosk',
                'password' => Hash::make(Str::random(40)), // password acak, tidak pernah dipakai login
                'role_id' => $kasirRole->id,
                'tenant_id' => $firstTenantId,
            ]
        );

        // 6. Seed data Modifiers untuk self-order
        $this->call(ModifierSeeder::class);

        // 7. Seed nilai default System Settings
        $this->call(SettingSeeder::class);

        // 8. Seed full POS realistic data (12 months, ~50K orders)
        $this->call(PosFullSeeder::class);
    }
}
