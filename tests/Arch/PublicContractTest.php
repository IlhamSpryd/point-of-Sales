<?php

declare(strict_types=1);

namespace Tests\Arch;

use PHPUnit\Framework\TestCase;

/**
 * Architecture Guardrail: Public Interface Contracts Integrity (ADR-101)
 * Enforces that every file residing in a Contracts directory must strictly declare a PHP interface.
 */
class PublicContractTest extends TestCase
{
    public function test_all_contract_files_declare_interfaces(): void
    {
        $basePath = dirname(__DIR__, 2);
        $searchDirs = [
            $basePath.'/app/Core/Contracts',
        ];

        $modulesDir = $basePath.'/app/Modules';
        if (is_dir($modulesDir)) {
            $modules = scandir($modulesDir) ?: [];
            foreach ($modules as $module) {
                if ($module !== '.' && $module !== '..' && is_dir($modulesDir.'/'.$module.'/Contracts')) {
                    $searchDirs[] = $modulesDir.'/'.$module.'/Contracts';
                }
            }
        }

        $nonInterfaces = [];
        $scannedCount = 0;

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

                $scannedCount++;
                $content = file_get_contents($file->getRealPath());
                if ($content === false || ! preg_match('/\binterface\s+[A-Za-z0-9_]+/', $content)) {
                    $nonInterfaces[] = str_replace($basePath.'/', '', $file->getRealPath());
                }
            }
        }

        $this->assertGreaterThan(0, $scannedCount, 'Expected at least one contract interface to be scanned.');
        $this->assertEmpty(
            $nonInterfaces,
            'Files in Contracts directories that do not declare an interface: '.implode(', ', $nonInterfaces).'. Concrete implementations must be in Services or Models, not Contracts.'
        );
    }
}
