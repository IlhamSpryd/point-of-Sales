<?php

declare(strict_types=1);

namespace Tests\Arch;

use PHPUnit\Framework\TestCase;

/**
 * Architecture Guardrail: Module Manifests & 13 Bounded Contexts Integrity (ADR-101 / ADR-102)
 */
class ModuleManifestTest extends TestCase
{
    public const CANONICAL_MODULES = [
        'Catalog',
        'Customers',
        'Finance',
        'Integration',
        'Inventory',
        'Manufacturing',
        'Organization',
        'Pricing',
        'Purchasing',
        'Reporting',
        'Sales',
        'Subscription',
        'Workforce',
    ];

    public function test_exact_thirteen_bounded_contexts_exist(): void
    {
        $basePath = dirname(__DIR__, 2);
        $modulesDir = $basePath.'/app/Modules';
        $this->assertDirectoryExists($modulesDir);

        $discoveredModules = array_values(array_filter(
            scandir($modulesDir) ?: [],
            fn ($item) => ! in_array($item, ['.', '..'], true) && is_dir($modulesDir.'/'.$item)
        ));

        sort($discoveredModules);
        $expectedModules = self::CANONICAL_MODULES;
        sort($expectedModules);

        $this->assertSame(
            $expectedModules,
            $discoveredModules,
            'app/Modules must strictly contain the exact 13 canonical Bounded Contexts (ADR-102).'
        );
    }

    public function test_every_bounded_context_has_valid_module_manifest(): void
    {
        $basePath = dirname(__DIR__, 2);
        $modulesDir = $basePath.'/app/Modules';

        $requiredKeys = ['name', 'tier', 'description', 'ownsTables', 'dependsOn', 'allowedDependencies', 'publishesEvents', 'consumesEvents'];

        foreach (self::CANONICAL_MODULES as $module) {
            $manifestPath = "{$modulesDir}/{$module}/module.json";
            $this->assertFileExists($manifestPath, "Module [{$module}] is missing module.json manifest.");

            $raw = file_get_contents($manifestPath);
            $this->assertNotFalse($raw, "Unable to read module.json for [{$module}].");

            $data = json_decode($raw, true);
            $this->assertIsArray($data, "Invalid JSON structure in module.json for [{$module}].");

            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey($key, $data, "module.json in [{$module}] is missing required key [{$key}].");
            }

            $this->assertSame($module, $data['name'], "Module name in manifest does not match directory [{$module}].");
            $this->assertIsInt($data['tier'], "Tier in [{$module}] must be an integer (1..6).");
            $this->assertGreaterThanOrEqual(1, $data['tier']);
            $this->assertLessThanOrEqual(6, $data['tier']);

            $this->assertIsArray($data['ownsTables'], "ownsTables in [{$module}] must be an array.");
            $this->assertIsArray($data['dependsOn'], "dependsOn in [{$module}] must be an array.");
            $this->assertIsArray($data['allowedDependencies'], "allowedDependencies in [{$module}] must be an array.");

            foreach ($data['dependsOn'] as $dep) {
                $this->assertContains(
                    $dep,
                    self::CANONICAL_MODULES,
                    "Module [{$module}] declares unknown dependency [{$dep}] in dependsOn."
                );
            }

            foreach ($data['allowedDependencies'] as $dep) {
                $this->assertContains(
                    $dep,
                    self::CANONICAL_MODULES,
                    "Module [{$module}] declares unknown dependency [{$dep}] in allowedDependencies."
                );
            }
        }
    }
}
