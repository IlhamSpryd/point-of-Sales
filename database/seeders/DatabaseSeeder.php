<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $role = \App\Models\Role::firstOrCreate(['name' => 'Super Admin']);

        \App\Models\User::updateOrCreate(
            ['email' => 'ilhamsepriyadi@gmail.com'],
            [
                'name' => 'Super Admin',
                'password' => \Illuminate\Support\Facades\Hash::make('12345678'),
                'role_id' => $role->id,
            ]
        );
    }
}
