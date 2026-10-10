<?php

namespace App\Console\Commands;

use App\Models\ProvisioningNonce;
use Illuminate\Console\Command;

/**
 * Domain 1 — hapus nonce anti-replay yang sudah melewati retensi.
 * Aman: nonce dengan expires_at_epoch sudah lewat TIDAK akan diterima
 * lagi pada request valid baru (timestamp window sudah berlalu).
 */
class PruneProvisioningNonces extends Command
{
    protected $signature = 'provisioning:prune-nonces';

    protected $description = 'Hapus provisioning_nonces yang sudah melewati masa retensi anti-replay';

    public function handle(): int
    {
        $deleted = ProvisioningNonce::where('expires_at_epoch', '<', now()->getTimestamp())->delete();
        $this->info("Pruned {$deleted} expired nonce(s).");

        return self::SUCCESS;
    }
}
