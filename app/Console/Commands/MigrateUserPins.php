<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

#[Signature('security:migrate-user-pins')]
#[Description('Migrate plaintext pin_code to secure pin_hash, and password to password_hash')]
class MigrateUserPins extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting security migration for user PINs and passwords...');

        $users = DB::table('users')->get();
        $migratedCount = 0;

        foreach ($users as $user) {
            $updates = [];

            // 1. PIN Hash
            if (!empty($user->pin_code)) {
                if (str_starts_with($user->pin_code, '$2y$') || str_starts_with($user->pin_code, '$argon')) {
                    $updates['pin_hash'] = $user->pin_code;
                } else {
                    $updates['pin_hash'] = Hash::make($user->pin_code);
                }
            }

            // 2. Password Hash
            if (!empty($user->password)) {
                $updates['password_hash'] = $user->password;
            }

            if (!empty($updates)) {
                DB::table('users')->where('id', $user->id)->update($updates);
                $migratedCount++;
            }
        }

        $this->info("Successfully migrated {$migratedCount} users.");
        $this->warn('Please verify the application works. DO NOT print PINs to the log or console.');
    }
}
