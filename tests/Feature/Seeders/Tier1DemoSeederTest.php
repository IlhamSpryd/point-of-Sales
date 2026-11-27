<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use Database\Seeders\Tier1DemoSeeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class Tier1DemoSeederTest extends TestCase
{
    public function test_seeder_refuses_to_run_on_test_database_without_explicit_opt_in(): void
    {
        Config::set('pos.allow_tier1_demo_test_db', false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tier1DemoSeeder menolak berjalan pada database uji');

        (new Tier1DemoSeeder)->run();
    }

    public function test_loyalty_ledger_seeder_preserves_hash_chain_across_chunks(): void
    {
        $source = File::get(database_path('seeders/Pos/LoyaltyLedgerSeeder.php'));

        $this->assertStringContainsString('$lastHashByCustomer = [];', $source);
        $this->assertStringContainsString('$prevHash = $lastHashByCustomer[$cid] ?? null;', $source);
        $this->assertStringNotContainsString('$lastHash = null;', $source);
    }
}
