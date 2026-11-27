<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Catatan: TIDAK memakai WithoutModelEvents. Model tenant-owned
     * (ModifierGroup, Category, Product, ...) mengandalkan event `creating`
     * dari AssignsTenant untuk men-stamp tenant_id; mematikan event model
     * akan membuat tenant_id NULL dan gagal di kolom NOT NULL (Phase 9).
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing', 'e2e'])) {
            throw new \RuntimeException('Seeders cannot run in production. Use migrate:fresh.');
        }

        // 0. Seed Statutory Rule Versions (BPJS, PPh 21, PTKP dll) - Global / Idempotent
        $this->call(StatutoryRuleVersionsSeeder::class);

        // 1. Seed fast deterministic Tier-1 Demo dataset (< 10s).
        // PosFullSeeder (12-month ~50K orders) tetap tersedia via `--class=PosFullSeeder`.
        $this->call(Tier1DemoSeeder::class);
    }
}
