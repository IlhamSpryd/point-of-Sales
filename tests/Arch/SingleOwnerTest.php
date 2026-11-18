<?php

declare(strict_types=1);

namespace Tests\Arch;

use PHPUnit\Framework\TestCase;

/**
 * Architecture Guardrail: Single Owner & Class Basename Uniqueness (ADR-101 / ADR-103)
 * Blocks introduction of new duplicate model or service basenames across modules.
 */
class SingleOwnerTest extends TestCase
{
    /**
     * Pre-existing legacy duplicate models slated for bounded-context unification in Phase 2.
     * All duplicate models eliminated in Phase 2 (ADR-101 / ADR-102).
     *
     * @var list<string>
     */
    private const ALLOWLISTED_DUPLICATE_MODELS = [];

    /**
     * Pre-existing legacy duplicate services slated for bounded-context unification in Phase 2.
     * All duplicate services eliminated in Phase 2 (ADR-101 / ADR-102).
     *
     * @var list<string>
     */
    private const ALLOWLISTED_DUPLICATE_SERVICES = [];

    public function test_no_new_duplicate_model_basenames_are_introduced(): void
    {
        $basePath = dirname(__DIR__, 2);
        $modulesDir = $basePath.'/app/Modules';
        $this->assertDirectoryExists($modulesDir);

        $modelOccurrences = [];
        $modules = array_filter(scandir($modulesDir) ?: [], fn ($item) => ! in_array($item, ['.', '..'], true) && is_dir($modulesDir.'/'.$item));

        foreach ($modules as $module) {
            $modelsPath = $modulesDir.'/'.$module.'/Models';
            if (! is_dir($modelsPath)) {
                continue;
            }

            $files = scandir($modelsPath) ?: [];
            foreach ($files as $file) {
                if (str_ends_with($file, '.php')) {
                    $basename = substr($file, 0, -4);
                    $modelOccurrences[$basename][] = $module;
                }
            }
        }

        $unallowlistedDuplicates = [];
        foreach ($modelOccurrences as $model => $moduleList) {
            if (count($moduleList) > 1 && ! in_array($model, self::ALLOWLISTED_DUPLICATE_MODELS, true)) {
                $unallowlistedDuplicates[$model] = $moduleList;
            }
        }

        $this->assertEmpty(
            $unallowlistedDuplicates,
            'New duplicate Model basenames discovered across modules: '.json_encode($unallowlistedDuplicates).'. Every new Model must have a single authoritative owner module.'
        );
    }

    public function test_no_new_duplicate_service_basenames_are_introduced(): void
    {
        $basePath = dirname(__DIR__, 2);
        $modulesDir = $basePath.'/app/Modules';
        $this->assertDirectoryExists($modulesDir);

        $serviceOccurrences = [];
        $modules = array_filter(scandir($modulesDir) ?: [], fn ($item) => ! in_array($item, ['.', '..'], true) && is_dir($modulesDir.'/'.$item));

        foreach ($modules as $module) {
            $servicesPath = $modulesDir.'/'.$module.'/Services';
            if (! is_dir($servicesPath)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($servicesPath));
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $basename = $file->getBasename('.php');
                    $serviceOccurrences[$basename][] = $module;
                }
            }
        }

        $unallowlistedDuplicates = [];
        foreach ($serviceOccurrences as $service => $moduleList) {
            if (count($moduleList) > 1 && ! in_array($service, self::ALLOWLISTED_DUPLICATE_SERVICES, true)) {
                $unallowlistedDuplicates[$service] = $moduleList;
            }
        }

        $this->assertEmpty(
            $unallowlistedDuplicates,
            'New duplicate Service basenames discovered across modules: '.json_encode($unallowlistedDuplicates).'. Every new Service must have a single authoritative owner module.'
        );
    }
}
