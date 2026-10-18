<?php

namespace App\Console\Commands;

use App\Models\EmailVerificationOtp;
use App\Models\UserInvitation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PruneIdentityChallenges extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'identity:prune-challenges';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune expired OTP challenges, password reset tokens, and update expired employee invitations.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting identity challenges pruning...');

        // 1. Hapus email_verification_otps yang sudah kedaluwarsa lebih dari 7 hari
        $deletedOtps = 0;
        if (Schema::hasTable('email_verification_otps')) {
            $deletedOtps = EmailVerificationOtp::where('expires_at', '<', now()->subDays(7))
                ->orWhere(function ($query) {
                    $query->whereNotNull('consumed_at')
                        ->where('consumed_at', '<', now()->subDays(7));
                })
                ->delete();
            $this->info("Pruned {$deletedOtps} expired email verification OTP records.");
        }

        // 2. Hapus password_reset_tokens yang berusia lebih dari 24 jam
        $deletedTokens = 0;
        if (Schema::hasTable('password_reset_tokens')) {
            $deletedTokens = DB::table('password_reset_tokens')
                ->where('created_at', '<', now()->subHours(24))
                ->delete();
            $this->info("Pruned {$deletedTokens} expired password reset tokens.");
        }

        // 3. Perbarui status user_invitations yang telah melewati expires_at menjadi 'expired'
        $expiredInvitations = 0;
        if (Schema::hasTable('user_invitations')) {
            $expiredInvitations = UserInvitation::where('status', UserInvitation::STATUS_PENDING)
                ->where('expires_at', '<', now())
                ->update(['status' => UserInvitation::STATUS_EXPIRED]);
            $this->info("Updated {$expiredInvitations} pending employee invitations to expired.");
        }

        $this->info('Identity challenges pruning completed successfully.');

        return Command::SUCCESS;
    }
}
