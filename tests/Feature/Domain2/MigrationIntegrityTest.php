<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * [D2-BE-001] MigrationIntegrityTest.
 *
 * Memastikan seluruh migrasi berjalan bersih pada database kosong tanpa
 * duplikasi tabel/constraint (membuktikan tidak ada lagi pasangan migrasi
 * duplikat seperti z_01_* s.d. z_05_* versus seri 2026_12_04_*).
 *
 * @group chaos
 */
#[Group('chaos')]
class MigrationIntegrityTest extends TestCase
{
    public function test_full_migration_suite_runs_clean_from_scratch(): void
    {
        $app = $this->createApplication();
        $kernel = $app->make(Kernel::class);
        $kernel->call('migrate:fresh', ['--force' => true, '--seed' => true]);
        $output = $kernel->output();

        $this->assertStringNotContainsString('SQLSTATE', $output);
        $this->assertStringNotContainsString('already exists', $output);

        $kernel->call('migrate:status');
        $this->assertStringNotContainsString('Pending', $kernel->output());
    }
}
