<?php

declare(strict_types=1);

namespace Tests\Arch;

use PHPUnit\Framework\TestCase;

/**
 * Architecture Guardrail: Anti-God-Class Line Limits & Ratchet Ceilings (ADR-101 / Risk R-04)
 * Enforces maximum line bounds: Services <= 800 lines, Livewire <= 500 lines.
 * Known oversized classes are locked with a +15 line ratchet ceiling to prevent uncontrolled bloat.
 */
class NoGodClassTest extends TestCase
{
    public const MAX_SERVICE_LINES = 800;

    public const MAX_LIVEWIRE_LINES = 500;

    public const MAX_HANDLER_LINES = 650;

    public const MAX_CONCERN_LINES = 500;

    /**
     * Ratchet ceilings for known pre-existing oversized Service classes (current lines + 15 line buffer).
     *
     * @var array<string, int>
     */
    private const OVERSIZED_SERVICES_RATCHET = [
        'app/Modules/Reporting/Services/Analytics/SalesAnalyticsService.php' => 825,
        'app/Modules/Purchasing/Services/PurchaseOrderService.php' => 905,
        'app/Modules/Finance/Services/AccountingPeriodService.php' => 1000,
        'app/Modules/Finance/Services/FinancialStatementService.php' => 910,
        'app/Modules/Finance/Services/CashBankTransactionService.php' => 880,
        'app/Modules/Inventory/Services/StockOpnameService.php' => 835,
        'app/Modules/Sales/Services/SalesOrderService.php' => 825,
        'app/Modules/Sales/Services/ShiftService.php' => 855,
        'app/Modules/Sales/Services/ReturnRefundService.php' => 1485,
        'app/Modules/Sales/Services/AccountsReceivableService.php' => 1065,
    ];

    /**
     * Ratchet ceilings for known pre-existing oversized Livewire classes (current lines + 15 line buffer).
     *
     * @var array<string, int>
     */
    private const OVERSIZED_LIVEWIRE_RATCHET = [
        'app/Livewire/ShiftManager.php' => 540,
        'app/Livewire/Customers/CustomerManager.php' => 745,
        'app/Livewire/Purchasing/SupplierManager.php' => 540,
        'app/Livewire/Kasir/OrderHistory.php' => 680,
    ];

    public function test_services_do_not_exceed_line_limit_or_ratchet_ceiling(): void
    {
        $basePath = dirname(__DIR__, 2);
        $searchDirs = [
            $basePath.'/app/Modules',
            $basePath.'/app/Services',
        ];

        $violations = [];

        foreach ($searchDirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $filePath = $file->getRealPath();
                $relPath = str_replace($basePath.'/', '', $filePath);

                // Check if this is inside a Services directory
                if (! str_contains($relPath, '/Services/') && ! str_starts_with($relPath, 'app/Services/')) {
                    continue;
                }

                $lines = count(file($filePath, FILE_IGNORE_NEW_LINES) ?: []);

                if (isset(self::OVERSIZED_SERVICES_RATCHET[$relPath])) {
                    $ceiling = self::OVERSIZED_SERVICES_RATCHET[$relPath];
                    if ($lines > $ceiling) {
                        $violations[] = "{$relPath} has {$lines} lines, exceeding ratchet ceiling of {$ceiling}";
                    }
                } elseif ($lines > self::MAX_SERVICE_LINES) {
                    $violations[] = "{$relPath} has {$lines} lines, exceeding max service limit of ".self::MAX_SERVICE_LINES;
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "God Class violations found in Service layer:\n".implode("\n", $violations)
        );
    }

    public function test_livewire_components_do_not_exceed_line_limit_or_ratchet_ceiling(): void
    {
        $basePath = dirname(__DIR__, 2);
        $livewireDir = $basePath.'/app/Livewire';
        $this->assertDirectoryExists($livewireDir);

        $violations = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($livewireDir));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $filePath = $file->getRealPath();
            $relPath = str_replace($basePath.'/', '', $filePath);
            $lines = count(file($filePath, FILE_IGNORE_NEW_LINES) ?: []);

            if (isset(self::OVERSIZED_LIVEWIRE_RATCHET[$relPath])) {
                $ceiling = self::OVERSIZED_LIVEWIRE_RATCHET[$relPath];
                if ($lines > $ceiling) {
                    $violations[] = "{$relPath} has {$lines} lines, exceeding ratchet ceiling of {$ceiling}";
                }
            } elseif ($lines > self::MAX_LIVEWIRE_LINES) {
                $violations[] = "{$relPath} has {$lines} lines, exceeding max Livewire limit of ".self::MAX_LIVEWIRE_LINES;
            }
        }

        $this->assertEmpty(
            $violations,
            "God Class violations found in Livewire components:\n".implode("\n", $violations)
        );
    }

    public function test_application_handlers_do_not_exceed_line_limit(): void
    {
        $basePath = dirname(__DIR__, 2);
        $modulesDir = $basePath.'/app/Modules';
        $this->assertDirectoryExists($modulesDir);

        $violations = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($modulesDir));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $filePath = $file->getRealPath();
            $relPath = str_replace($basePath.'/', '', $filePath);

            if (! str_contains($relPath, '/Application/Handlers/')) {
                continue;
            }

            $lines = count(file($filePath, FILE_IGNORE_NEW_LINES) ?: []);
            if ($lines > self::MAX_HANDLER_LINES) {
                $violations[] = "{$relPath} has {$lines} lines, exceeding max application handler limit of ".self::MAX_HANDLER_LINES;
            }
        }

        $this->assertEmpty(
            $violations,
            "God Class violations found in Application Handlers:\n".implode("\n", $violations)
        );
    }

    public function test_livewire_concerns_do_not_exceed_line_limit(): void
    {
        $basePath = dirname(__DIR__, 2);
        $livewireDir = $basePath.'/app/Livewire';
        $this->assertDirectoryExists($livewireDir);

        $violations = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($livewireDir));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $filePath = $file->getRealPath();
            $relPath = str_replace($basePath.'/', '', $filePath);

            if (! str_contains($relPath, '/Concerns/')) {
                continue;
            }

            $lines = count(file($filePath, FILE_IGNORE_NEW_LINES) ?: []);
            if ($lines > self::MAX_CONCERN_LINES) {
                $violations[] = "{$relPath} has {$lines} lines, exceeding max Livewire concern limit of ".self::MAX_CONCERN_LINES;
            }
        }

        $this->assertEmpty(
            $violations,
            "God Class violations found in Livewire concerns:\n".implode("\n", $violations)
        );
    }

    public function test_reporting_metric_providers_do_not_exceed_line_limit(): void
    {
        $basePath = dirname(__DIR__, 2);
        $providersDir = $basePath.'/app/Modules/Reporting/Services/Providers';
        $this->assertDirectoryExists($providersDir);

        $violations = [];
        $files = glob($providersDir.'/*.php') ?: [];
        $this->assertNotEmpty($files, 'Reporting metric providers must exist.');

        foreach ($files as $filePath) {
            $relPath = str_replace($basePath.'/', '', $filePath);
            $lines = count(file($filePath, FILE_IGNORE_NEW_LINES) ?: []);

            if ($lines > 450) {
                $violations[] = "{$relPath} has {$lines} lines, exceeding decomposed provider limit of 450 lines.";
            }
        }

        $this->assertEmpty(
            $violations,
            "Metric provider line limit violations:\n".implode("\n", $violations)
        );
    }
}
