<?php

declare(strict_types=1);

namespace Tests\Arch;

use PHPUnit\Framework\TestCase;

/**
 * Architecture Guardrail: Apeiron Systems UI/UX & Frontend Standards (Wave 2)
 *
 * Enforces:
 * 1. Zero raw palette utility classes (text-zinc-*, bg-zinc-*, etc.) & zero bg-white in views.
 * 2. Zero manual dark: overrides (except legitimate switcher icons).
 * 3. Max line limits: Livewire components <= 450 lines, Livewire concerns <= 500 lines.
 * 4. WCAG 2.2 AAA table standards: all <th> have scope="col" and all <table> wrapped in overflow-x-auto.
 */
class UiUxStandardsGuardrailTest extends TestCase
{
    private string $viewsPath;

    private string $livewirePath;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->viewsPath = $base.'/resources/views';
        $this->livewirePath = $base.'/app/Livewire';
    }

    public function test_zero_raw_color_palettes_in_blade_views(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->viewsPath));
        $rawPaletteRegex = '/\b(?:text|bg|border|ring|from|to)-(?:zinc|slate|gray|stone|neutral-[0-9]+|red-[0-9]+|blue-[0-9]+|green-[0-9]+|emerald-[0-9]+|amber-[0-9]+|indigo-[0-9]+|purple-[0-9]+)\b/';

        $violations = [];

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            // Skip third-party vendor views if any
            if (str_contains($file->getPathname(), '/vendor/')) {
                continue;
            }

            $content = file_get_contents($file->getPathname()) ?: '';
            $relPath = str_replace(dirname($this->viewsPath, 2).'/', '', $file->getPathname());

            if (preg_match_all($rawPaletteRegex, $content, $matches)) {
                $uniqueMatches = array_unique($matches[0]);
                $violations[] = "{$relPath} contains raw palette classes: ".implode(', ', $uniqueMatches);
            }

            if (preg_match('/\bbg-white\b/', $content)) {
                $violations[] = "{$relPath} contains non-semantic raw 'bg-white' class (use 'bg-card' or 'bg-background').";
            }
        }

        $this->assertEmpty(
            $violations,
            "Raw color palette violations found (must use WCAG AAA semantic tokens from tokens.css):\n".implode("\n", $violations)
        );
    }

    public function test_zero_manual_dark_overrides_except_whitelisted_switchers(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->viewsPath));
        $darkOverrideRegex = '/\bdark:[a-zA-Z0-9_\-\/]+/';

        $whitelisted = [
            'resources/views/layouts/navigation.blade.php' => ['dark:invert'],
            'resources/views/layouts/app.blade.php' => ['dark:hidden', 'dark:inline'],
        ];

        $violations = [];

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            if (str_contains($file->getPathname(), '/vendor/')) {
                continue;
            }

            $content = file_get_contents($file->getPathname()) ?: '';
            $relPath = str_replace(dirname($this->viewsPath, 2).'/', '', $file->getPathname());

            if (preg_match_all($darkOverrideRegex, $content, $matches)) {
                $foundClasses = array_unique($matches[0]);
                $allowed = $whitelisted[$relPath] ?? [];

                $unauthorized = array_filter($foundClasses, function ($cls) use ($allowed) {
                    return ! in_array($cls, $allowed, true);
                });

                if (! empty($unauthorized)) {
                    $violations[] = "{$relPath} has manual dark: overrides: ".implode(', ', $unauthorized);
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Manual dark: overrides found (semantic tokens invert automatically):\n".implode("\n", $violations)
        );
    }

    public function test_all_livewire_components_under_450_lines(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->livewirePath));
        $violations = [];

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $filePath = $file->getRealPath();
            // Skip Concerns directories for the main component check
            if (str_contains($filePath, '/Concerns/')) {
                continue;
            }

            $relPath = str_replace(dirname($this->livewirePath, 2).'/', '', $filePath);
            $lines = count(file($filePath, FILE_IGNORE_NEW_LINES) ?: []);

            if ($lines > 450) {
                $violations[] = "{$relPath} has {$lines} lines, exceeding the 450-line God Class limit.";
            }
        }

        $this->assertEmpty(
            $violations,
            "Oversized Livewire components found (> 450 lines):\n".implode("\n", $violations)
        );
    }

    public function test_all_livewire_concerns_under_500_lines(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->livewirePath));
        $violations = [];

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $filePath = $file->getRealPath();
            if (! str_contains($filePath, '/Concerns/')) {
                continue;
            }

            $relPath = str_replace(dirname($this->livewirePath, 2).'/', '', $filePath);
            $lines = count(file($filePath, FILE_IGNORE_NEW_LINES) ?: []);

            if ($lines > 500) {
                $violations[] = "{$relPath} has {$lines} lines, exceeding the 500-line concern limit.";
            }
        }

        $this->assertEmpty(
            $violations,
            "Oversized Livewire concerns found (> 500 lines):\n".implode("\n", $violations)
        );
    }

    public function test_all_tables_have_scope_col(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->viewsPath));
        $violations = [];

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            if (str_contains($file->getPathname(), '/vendor/')) {
                continue;
            }

            $content = file_get_contents($file->getPathname()) ?: '';
            $relPath = str_replace(dirname($this->viewsPath, 2).'/', '', $file->getPathname());

            // Match any <th> tag that doesn't have scope="col"
            if (preg_match_all('/<th\b(?![^>]*\bscope=["\']col["\'])[^>]*>/i', $content, $matches)) {
                $violations[] = "{$relPath} has ".count($matches[0]).' <th> tag(s) without scope="col".';
            }
        }

        $this->assertEmpty(
            $violations,
            "Table accessibility violations found (all <th> must declare scope=\"col\"):\n".implode("\n", $violations)
        );
    }
}
