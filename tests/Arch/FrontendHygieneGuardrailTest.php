<?php

declare(strict_types=1);

namespace Tests\Arch;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Wave 9C — Frontend Hygiene Guardrail Tests
 *
 * Prevents reintroduction of dead Tailwind token scale classes, legacy confirm
 * partials, and other UI hygiene regressions caught during the Wave 9C audit.
 */
class FrontendHygieneGuardrailTest extends TestCase
{
    private string $viewsDir;

    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = dirname(__DIR__, 2);
        $this->viewsDir = $this->projectRoot.'/resources/views';
    }

    /**
     * No dead primary/danger numeric scale Tailwind tokens in Blade views.
     *
     * Allowed: bg-primary, text-primary-foreground, bg-destructive, text-destructive.
     * Forbidden: ring-primary-500, text-primary-700, text-danger-NNN, bg-danger-NNN, etc.
     */
    public function test_no_dead_primary_danger_scale_tokens_in_views(): void
    {
        /** @var list<string> $violations */
        $violations = [];

        $pattern = '/(ring-primary-\d|border-primary-\d{3}|text-primary-\d{3}|bg-primary-\d{3}|focus:ring-primary-\d|hover:bg-primary-\d|text-danger-\d|bg-danger-\d|border-danger-\d|ring-danger-\d)/';

        foreach ($this->iterateBladeFiles() as $file) {
            $content = file_get_contents($file);
            if (preg_match($pattern, $content, $matches)) {
                preg_match_all($pattern, $content, $allMatches);
                $relative = str_replace($this->projectRoot.'/', '', $file);
                foreach (array_unique($allMatches[0]) as $token) {
                    $violations[] = "{$relative}: {$token}";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Dead primary/danger numeric-scale Tailwind tokens found in Blade views.\n"
            ."Use semantic tokens (bg-destructive, text-destructive, ring-ring, bg-primary, text-primary-foreground) instead:\n"
            .implode("\n", $violations)
        );
    }

    /**
     * No `placeholder-slate-` utility classes in Blade views.
     * Use `placeholder:text-muted-foreground` instead.
     */
    public function test_no_placeholder_slate_in_views(): void
    {
        $violations = [];

        foreach ($this->iterateBladeFiles() as $file) {
            $content = file_get_contents($file);
            if (str_contains($content, 'placeholder-slate-')) {
                $violations[] = str_replace($this->projectRoot.'/', '', $file);
            }
        }

        $this->assertEmpty(
            $violations,
            "placeholder-slate-NNN found. Use `placeholder:text-muted-foreground` instead:\n"
            .implode("\n", $violations)
        );
    }

    /**
     * The legacy sweetalert-confirm-delete partial must remain deleted.
     * All destructive confirmations must route through the ERP confirm dialog.
     */
    public function test_no_sweetalert_confirm_delete_partial(): void
    {
        $partialPath = $this->viewsDir.'/partials/sweetalert-confirm-delete.blade.php';

        $this->assertFileDoesNotExist(
            $partialPath,
            'Dead sweetalert-confirm-delete partial must remain deleted. '
            .'Use confirmDelete() / confirmSubmit() from erp/confirm-dialog instead.'
        );
    }

    /**
     * No hardcoded arbitrary hex color classes in Blade views.
     * e.g. text-[#abc123], bg-[#fff], border-[#333].
     */
    public function test_no_hardcoded_hex_color_classes_in_views(): void
    {
        $violations = [];
        $pattern = '/(text|bg|border|ring|fill|stroke)-\[#[0-9a-fA-F]{3,6}\]/';

        foreach ($this->iterateBladeFiles() as $file) {
            $content = file_get_contents($file);
            if (preg_match($pattern, $content)) {
                preg_match_all($pattern, $content, $allMatches);
                $relative = str_replace($this->projectRoot.'/', '', $file);
                foreach (array_unique($allMatches[0]) as $token) {
                    $violations[] = "{$relative}: {$token}";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Hardcoded hex color classes found in Blade views.\n"
            ."Use design system semantic tokens (bg-background, text-foreground, bg-card, etc.) instead:\n"
            .implode("\n", $violations)
        );
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * @return \Generator<string>
     */
    private function iterateBladeFiles(): \Generator
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->viewsDir)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getRealPath();
            }
        }
    }
}
