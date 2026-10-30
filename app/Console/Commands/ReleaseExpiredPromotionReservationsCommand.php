<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Pricing\Models\PromotionReservation;
use Illuminate\Console\Command;

class ReleaseExpiredPromotionReservationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'promotions:release-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Melepaskan seluruh reservasi promosi dan voucher yang telah melewati masa berlaku TTL (120 detik)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $now = now();

        $affected = PromotionReservation::where('status', PromotionReservation::STATUS_RESERVED)
            ->where('expires_at', '<', $now)
            ->update([
                'status' => PromotionReservation::STATUS_RELEASED,
                'updated_at' => $now,
            ]);

        $this->info("Berhasil melepaskan {$affected} reservasi promosi/voucher yang kedaluwarsa.");

        return self::SUCCESS;
    }
}
