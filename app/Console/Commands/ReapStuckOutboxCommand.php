<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Outbox\OutboxReaperService;
use Illuminate\Console\Command;

class ReapStuckOutboxCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'outbox:reap-stuck {--minutes=5 : Threshold in minutes to consider a processing row stuck}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reaps stuck PROCESSING outbox rows back to PENDING or DEAD_LETTER (OPS-04, ADR-107)';

    /**
     * Execute the console command.
     */
    public function handle(OutboxReaperService $reaper): int
    {
        $minutes = (int) $this->option('minutes');

        if ($minutes <= 0) {
            $this->error('The --minutes option must be a positive integer greater than zero.');

            return self::FAILURE;
        }

        $this->info("Scanning for outbox rows stuck in PROCESSING for >= {$minutes} minutes...");

        $reaped = $reaper->reapStuckProcessingRows($minutes);

        $this->info("Successfully reaped {$reaped} stuck outbox record(s).");

        return self::SUCCESS;
    }
}
