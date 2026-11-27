<?php

declare(strict_types=1);

namespace Tests\Arch;

use PHPUnit\Framework\TestCase;

/**
 * Architecture Guardrail: Tenant Isolation & Scope Bypass Ratchet (ADR-104 / FND-02)
 * Ratchet down tenant scope bypasses across releases to prevent multi-tenant data leaks.
 */
class TenantScopeBypassTest extends TestCase
{
    /**
     * Ratchet ceiling for total allowable scope bypasses in app/ (must decrease each milestone).
     */
    public const MAX_ALLOWED_BYPASSES = 680;

    /**
     * Ratchet ceiling for legacy unannotated bypasses. Any new bypass must be annotated with @tenant-bypass.
     */
    public const MAX_UNANNOTATED_BYPASSES = 350;

    /**
     * Recognized valid categories for @tenant-bypass annotations.
     *
     * @var list<string>
     */
    public const VALID_BYPASS_CATEGORIES = [
        'superadmin',
        'seeder',
        'dataset_generation',
        'auth_lookup',
        'cross_tenant_reconciliation',
        'migration',
        'maintenance',
        'platform_entitlement',
        'console_command',
        'background_job',
        'offline_sync',
    ];

    public function test_total_tenant_scope_bypasses_do_not_exceed_ratchet_ceiling(): void
    {
        $basePath = dirname(__DIR__, 2);
        $appDir = $basePath.'/app';
        $this->assertDirectoryExists($appDir);

        $bypasses = [];
        $unannotated = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($appDir));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $lines = file($file->getRealPath(), FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $lineNum => $line) {
                if (preg_match('/\b(withoutGlobalScope|withoutGlobalScopes|withoutTenantScope|runWithoutTenant)\b/', $line)) {
                    $bypasses[] = [
                        'file' => str_replace($basePath.'/', '', $file->getRealPath()),
                        'line' => $lineNum + 1,
                        'code' => trim($line),
                    ];

                    $prevLine = $lineNum > 0 ? $lines[$lineNum - 1] : '';
                    if (! str_contains($line, '@tenant-bypass:') && ! str_contains($prevLine, '@tenant-bypass:')) {
                        $unannotated[] = str_replace($basePath.'/', '', $file->getRealPath()).':'.($lineNum + 1);
                    }
                }
            }
        }

        $totalBypasses = count($bypasses);
        $totalUnannotated = count($unannotated);

        $this->assertLessThanOrEqual(
            self::MAX_ALLOWED_BYPASSES,
            $totalBypasses,
            "Total tenant isolation bypasses ({$totalBypasses}) exceeded ratchet ceiling (".self::MAX_ALLOWED_BYPASSES.'). Under ADR-104, bypasses must decrease monotonically.'
        );

        $this->assertLessThanOrEqual(
            self::MAX_UNANNOTATED_BYPASSES,
            $totalUnannotated,
            "Unannotated tenant bypasses ({$totalUnannotated}) exceeded baseline (".self::MAX_UNANNOTATED_BYPASSES."). Any new bypass must be accompanied by an explicit '// @tenant-bypass: <reason>' annotation on the same or preceding line."
        );
    }

    public function test_annotated_tenant_bypasses_contain_valid_reasons(): void
    {
        $basePath = dirname(__DIR__, 2);
        $appDir = $basePath.'/app';

        $invalidAnnotations = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($appDir));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getRealPath());
            if ($content === false || ! str_contains($content, '@tenant-bypass')) {
                continue;
            }

            $lines = explode("\n", $content);
            foreach ($lines as $lineNum => $line) {
                if (preg_match('/@tenant-bypass:\s*([A-Za-z0-9_\-]+)/', $line, $matches)) {
                    $category = strtolower($matches[1]);
                    if (! in_array($category, self::VALID_BYPASS_CATEGORIES, true)) {
                        $invalidAnnotations[] = str_replace($basePath.'/', '', $file->getRealPath()).':'.($lineNum + 1)." (invalid category: '{$category}')";
                    }
                }
            }
        }

        $this->assertEmpty(
            $invalidAnnotations,
            'Invalid @tenant-bypass annotations found: '.implode(', ', $invalidAnnotations).'. Must match registered categories: '.implode(', ', self::VALID_BYPASS_CATEGORIES)
        );
    }
}
