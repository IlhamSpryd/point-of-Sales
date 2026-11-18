<?php

declare(strict_types=1);

namespace Tests\Arch;

use PHPUnit\Framework\TestCase;

/**
 * Architecture Guardrail: Tier Dependency Direction & Acyclic Enforcement (ADR-101 / ADR-102)
 */
class DependencyDirectionTest extends TestCase
{
    public function test_dependencies_strictly_follow_tier_hierarchy_and_manifest_allowlists(): void
    {
        $basePath = dirname(__DIR__, 2);
        $modulesDir = $basePath.'/app/Modules';
        $this->assertDirectoryExists($modulesDir);

        $manifests = [];
        $modules = array_filter(scandir($modulesDir) ?: [], fn ($item) => ! in_array($item, ['.', '..'], true) && is_dir($modulesDir.'/'.$item));

        foreach ($modules as $module) {
            $manifestFile = "{$modulesDir}/{$module}/module.json";
            if (file_exists($manifestFile)) {
                $manifests[$module] = json_decode((string) file_get_contents($manifestFile), true);
            }
        }

        $violations = [];

        foreach ($modules as $module) {
            $moduleTier = $manifests[$module]['tier'] ?? 99;
            $allowedDeps = $manifests[$module]['dependsOn'] ?? $manifests[$module]['allowedDependencies'] ?? [];

            $modulePath = "{$modulesDir}/{$module}";
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

                if (preg_match_all('/use\s+App\\\\Modules\\\\([A-Za-z0-9_]+)\\\\([^;]+);/', $content, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $targetModule = $match[1];
                        $targetPath = $match[2];

                        if ($targetModule === $module) {
                            continue; // Intra-module import is always legal
                        }

                        // Platform imports (App\Platform\...) are always allowed
                        // If cross-module:
                        $targetTier = $manifests[$targetModule]['tier'] ?? 99;
                        $isContractOrEvent = str_starts_with($targetPath, 'Contracts\\') || str_starts_with($targetPath, 'Events\\');

                        // Is allowed by explicit allowedDependencies in manifest?
                        $isExplicitlyAllowed = in_array($targetModule, $allowedDeps, true);

                        // If target module is higher or equal tier and not contract/event and not allowed:
                        if ($targetTier >= $moduleTier && ! $isExplicitlyAllowed && ! $isContractOrEvent) {
                            $violations[] = sprintf(
                                '[%s (Tier %d)] in %s illegally imports [%s (Tier %d)\\%s]',
                                $module,
                                $moduleTier,
                                str_replace($basePath.'/', '', $file->getRealPath()),
                                $targetModule,
                                $targetTier,
                                $targetPath
                            );
                        }
                    }
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Illegal reverse/cyclic dependencies discovered violating 6-Tier architecture:\n".implode("\n", $violations)
        );
    }

    public function test_finance_has_zero_imports_of_sales_models(): void
    {
        $basePath = dirname(__DIR__, 2);
        $financeDir = $basePath.'/app/Modules/Finance';
        $this->assertDirectoryExists($financeDir);

        $violations = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($financeDir));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = (string) file_get_contents($file->getRealPath());
            if (preg_match_all('/use\s+App\\\\Modules\\\\Sales\\\\Models\\\\([^;]+);/', $content, $matches)) {
                $violations[str_replace($basePath.'/', '', $file->getRealPath())] = $matches[1];
            }
        }

        $this->assertEmpty(
            $violations,
            "Finance illegally imports Sales\\Models directly (violates ADR-111 decoupling):\n".json_encode($violations, JSON_PRETTY_PRINT)
        );
    }

    public function test_customers_has_zero_imports_of_sales_models(): void
    {
        $basePath = dirname(__DIR__, 2);
        $customersDir = $basePath.'/app/Modules/Customers';
        $this->assertDirectoryExists($customersDir);

        $violations = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($customersDir));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = (string) file_get_contents($file->getRealPath());
            if (preg_match_all('/use\s+App\\\\Modules\\\\Sales\\\\Models\\\\([^;]+);/', $content, $matches)) {
                $violations[str_replace($basePath.'/', '', $file->getRealPath())] = $matches[1];
            }
        }

        $this->assertEmpty(
            $violations,
            "Customers illegally imports Sales\\Models directly (violates ADR-111 decoupling):\n".json_encode($violations, JSON_PRETTY_PRINT)
        );
    }

    public function test_sales_has_zero_imports_of_midtrans(): void
    {
        $basePath = dirname(__DIR__, 2);
        $salesDir = $basePath.'/app/Modules/Sales';
        $this->assertDirectoryExists($salesDir);

        $violations = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($salesDir));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = (string) file_get_contents($file->getRealPath());
            if (preg_match_all('/use\s+Midtrans\\\\([^;]+);/', $content, $matches)) {
                $violations[str_replace($basePath.'/', '', $file->getRealPath())] = $matches[1];
            }
        }

        $this->assertEmpty(
            $violations,
            "Sales module illegally imports Midtrans SDK directly (violates ADR-107 anti-corruption layer):\n".json_encode($violations, JSON_PRETTY_PRINT)
        );
    }
}
