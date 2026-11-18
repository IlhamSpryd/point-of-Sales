<?php

declare(strict_types=1);

namespace Tests\Arch;

use PHPUnit\Framework\TestCase;

/**
 * Architecture Guardrail: Module Boundary & Cross-Domain Integrity (ADR-101 / ADR-104)
 */
class ModuleBoundaryTest extends TestCase
{
    public const MAX_CROSS_MODULE_IMPORTS = 760;

    public function test_no_wrapper_classes_remain_in_app_models_and_app_services(): void
    {
        $basePath = dirname(__DIR__, 2);

        $modelsDir = $basePath.'/app/Models';
        $modelFiles = is_dir($modelsDir)
            ? array_filter(scandir($modelsDir) ?: [], fn ($f) => str_ends_with($f, '.php'))
            : [];
        $this->assertEmpty(
            $modelFiles,
            'Legacy wrapper classes still exist in app/Models: '.implode(', ', $modelFiles).'. All models must reside in canonical app/Modules/<Context>/Models.'
        );

        $servicesDir = $basePath.'/app/Services';
        $serviceFiles = is_dir($servicesDir)
            ? array_filter(scandir($servicesDir) ?: [], fn ($f) => str_ends_with($f, '.php'))
            : [];
        $this->assertEmpty(
            $serviceFiles,
            'Legacy wrapper classes still exist in app/Services: '.implode(', ', $serviceFiles).'. All services must reside in canonical app/Modules/<Context>/Services or app/Platform.'
        );
    }

    public function test_cross_module_imports_do_not_exceed_ratchet_ceiling(): void
    {
        $basePath = dirname(__DIR__, 2);
        $modulesDir = $basePath.'/app/Modules';
        $this->assertDirectoryExists($modulesDir);

        $crossModuleImports = [];
        $modules = array_filter(scandir($modulesDir) ?: [], fn ($item) => ! in_array($item, ['.', '..'], true) && is_dir($modulesDir.'/'.$item));

        foreach ($modules as $module) {
            $modulePath = $modulesDir.'/'.$module;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($modulePath));

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $content = file_get_contents($file->getRealPath());
                if ($content === false) {
                    continue;
                }

                if (preg_match_all('/use\s+App\\\\Modules\\\\([A-Za-z0-9_]+)\\\\/', $content, $matches)) {
                    foreach ($matches[1] as $targetModule) {
                        if ($targetModule !== $module) {
                            $crossModuleImports[] = [
                                'file' => str_replace($basePath.'/', '', $file->getRealPath()),
                                'source_module' => $module,
                                'target_module' => $targetModule,
                            ];
                        }
                    }
                }
            }
        }

        $count = count($crossModuleImports);
        $this->assertLessThanOrEqual(
            self::MAX_CROSS_MODULE_IMPORTS,
            $count,
            "Cross-module import count ({$count}) exceeded ratchet ceiling (".self::MAX_CROSS_MODULE_IMPORTS.'). Refactor to bounded contexts or contracts instead of adding new direct cross-module imports.'
        );
    }

    public function test_raw_journal_tables_are_strictly_forbidden_outside_finance_module(): void
    {
        $basePath = dirname(__DIR__, 2);
        $modulesDir = $basePath.'/app/Modules';
        $this->assertDirectoryExists($modulesDir);

        $violations = [];
        $modules = array_filter(scandir($modulesDir) ?: [], fn ($item) => ! in_array($item, ['.', '..'], true) && is_dir($modulesDir.'/'.$item));

        foreach ($modules as $module) {
            if ($module === 'Finance') {
                continue;
            }

            $modulePath = $modulesDir.'/'.$module;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($modulePath));

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $content = file_get_contents($file->getRealPath());
                if ($content === false) {
                    continue;
                }

                if (preg_match("/DB::table\(\s*['\"](journal_entries|journal_lines)['\"]\s*\)/", $content, $matches)) {
                    $violations[] = str_replace($basePath.'/', '', $file->getRealPath())." (matches {$matches[0]})";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            'Raw queries to journal_entries or journal_lines found outside App\\Modules\\Finance: '.implode(', ', $violations).'. Interact via Finance service contracts or domain models instead.'
        );
    }
}
