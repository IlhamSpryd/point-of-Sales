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
    protected $signature = 'identity:prune-challenges
                            {--activity-days=90 : Hari retensi log aktivitas audit sebelum dipangkas (0 untuk lewati)}
                            {--dry-run : Tampilkan estimasi baris yang akan dibersihkan tanpa menghapus}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune expired OTP challenges, password reset tokens, expired employee invitations, and aged activity audit logs.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->info('Starting identity challenges and audit log pruning...'.($dryRun ? ' [DRY RUN]' : ''));

        // 1. Hapus email_verification_otps yang sudah kedaluwarsa lebih dari 7 hari
        $deletedOtps = 0;
        if (Schema::hasTable('email_verification_otps')) {
            $otpQuery = EmailVerificationOtp::where('expires_at', '<', now()->subDays(7))
                ->orWhere(function ($query) {
                    $query->whereNotNull('consumed_at')
                        ->where('consumed_at', '<', now()->subDays(7));
                });

            if ($dryRun) {
                $deletedOtps = $otpQuery->count();
                $this->info("[DRY RUN] Would prune {$deletedOtps} expired email verification OTP records.");
            } else {
                $deletedOtps = $otpQuery->delete();
                $this->info("Pruned {$deletedOtps} expired email verification OTP records.");
            }
        }

        // 2. Hapus password_reset_tokens yang berusia lebih dari 24 jam
        $deletedTokens = 0;
        if (Schema::hasTable('password_reset_tokens')) {
            $tokenQuery = DB::table('password_reset_tokens')
                ->where('created_at', '<', now()->subHours(24));

            if ($dryRun) {
                $deletedTokens = $tokenQuery->count();
                $this->info("[DRY RUN] Would prune {$deletedTokens} expired password reset tokens.");
            } else {
                $deletedTokens = $tokenQuery->delete();
                $this->info("Pruned {$deletedTokens} expired password reset tokens.");
            }
        }

        // 3. Perbarui status user_invitations yang telah melewati expires_at menjadi 'expired'
        $expiredInvitations = 0;
        if (Schema::hasTable('user_invitations')) {
            $invitationQuery = UserInvitation::where('status', UserInvitation::STATUS_PENDING)
                ->where('expires_at', '<', now());

            if ($dryRun) {
                $expiredInvitations = $invitationQuery->count();
                $this->info("[DRY RUN] Would update {$expiredInvitations} pending employee invitations to expired.");
            } else {
                $expiredInvitations = $invitationQuery->update(['status' => UserInvitation::STATUS_EXPIRED]);
                $this->info("Updated {$expiredInvitations} pending employee invitations to expired.");
            }
        }

        // 4. Pangkas activity_logs yang melewati batas retensi audit (F-M1-D7-01)
        $activityDays = (int) $this->option('activity-days');
        if ($activityDays > 0 && Schema::hasTable('activity_logs')) {
            $cutoff = now()->subDays($activityDays);
            $activityQuery = DB::table('activity_logs')->where('created_at', '<', $cutoff);

            if ($dryRun) {
                $count = $activityQuery->count();
                $this->info("[DRY RUN] Would prune {$count} activity log records older than {$activityDays} days.");
            } else {
                $deletedLogs = $activityQuery->delete();
                $this->info("Pruned {$deletedLogs} activity log records older than {$activityDays} days.");
            }
        }

        $this->info('Identity challenges and audit pruning completed successfully.');

        return Command::SUCCESS;
    }
}
