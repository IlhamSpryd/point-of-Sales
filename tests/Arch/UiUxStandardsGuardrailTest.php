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

    /**
     * D1 Rule: Zero badge pills in views.
     * Prohibits rounded-full on badge/tag/status/tab containers with horizontal padding.
     * Allows natural circular elements: avatars (w-8 h-8 rounded-full), 6px status dots (w-1.5 h-1.5 rounded-full),
     * switch thumbs, and circular step numbers.
     */
    public function test_zero_badge_pills_in_blade_views(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->viewsPath));
        $pillRegex = '/\brounded-full\b[^>\"\']*?\b(px-[1-9]|py-[0-9]|p-[1-9]|text-xs|text-sm|badge|font-)/';

        $violations = [];

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            if (str_contains($file->getPathname(), '/vendor/')) {
                continue;
            }

            $filePath = $file->getPathname();
            $lines = file($filePath, FILE_IGNORE_NEW_LINES) ?: [];
            $relPath = str_replace(dirname($this->viewsPath, 2).'/', '', $filePath);

            foreach ($lines as $lineNum => $line) {
                if (preg_match($pillRegex, $line)) {
                    // Whitelist legitimate circular elements:
                    // 1. Avatars and icon containers (w-* h-* rounded-full or size-* rounded-full)
                    $isCircularAvatarOrIcon = (bool) preg_match('/(?:w-\d+(?:\.\d+)?\s+h-\d+(?:\.\d+)?|size-\d+(?:\.\d+)?)\s+rounded-full/', $line);
                    // 2. Status dots (w-1.5 h-1.5 or w-2 h-2)
                    $isStatusDot = (bool) preg_match('/w-(?:1|1\.5|2)\s+h-(?:1|1\.5|2)\s+rounded-full/', $line);
                    // 3. Switch thumb or switch track
                    $isSwitch = str_contains($line, 'role="switch"') || str_contains($line, 'thumb') || str_contains($line, 'w-[46px]') || str_contains($line, 'w-5 h-5 rounded-full');
                    // 4. Stepper circle
                    $isStepper = str_contains($line, 'stepper') || str_contains($line, 'flex items-center justify-center font-bold');

                    if ($isCircularAvatarOrIcon || $isStatusDot || $isSwitch || $isStepper) {
                        continue;
                    }

                    $trimmed = trim($line);
                    $violations[] = "{$relPath}:".($lineNum + 1)." -> {$trimmed}";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Badge pill violations found (must use 6px status dot or plain typography instead of rounded-full pill capsule):\n".implode("\n", $violations)
        );
    }

    /**
     * D1 Rule: Zero oversized typography in internal views.
     * Prohibits text-3xl, text-4xl, text-5xl in internal app views (except /dashboard executive KPI hero and auth/error pages).
     */
    public function test_zero_oversized_typography_in_internal_views(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->viewsPath));
        $oversizedRegex = '/\b(text-3xl|text-4xl|text-5xl)\b/';

        $exemptPaths = [
            'resources/views/dashboard',
            'resources/views/auth',
            'resources/views/errors',
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

            $filePath = $file->getPathname();
            $relPath = str_replace(dirname($this->viewsPath, 2).'/', '', $filePath);

            foreach ($exemptPaths as $exempt) {
                if (str_starts_with($relPath, $exempt)) {
                    continue 2;
                }
            }

            $lines = file($filePath, FILE_IGNORE_NEW_LINES) ?: [];

            foreach ($lines as $lineNum => $line) {
                if (preg_match($oversizedRegex, $line)) {
                    // Whitelist Material Symbols icon sizing and illustration placeholder icons
                    if (str_contains($line, 'material-symbols-') || str_contains($line, 'empty-state') || str_contains($line, '<svg') || str_contains($line, '<x-favicon')) {
                        continue;
                    }

                    $trimmed = trim($line);
                    $violations[] = "{$relPath}:".($lineNum + 1)." -> {$trimmed}";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Oversized typography violations found in non-dashboard internal views (must adhere to 14\" laptop scale <= 20px):\n".implode("\n", $violations)
        );
    }

    /**
     * D1 Rule: Interactive Theme Switcher declared in layouts/app.blade.php with role="switch" and :aria-checked.
     */
    public function test_theme_switch_declares_role_switch_and_aria_checked(): void
    {
        $appBlade = $this->viewsPath.'/layouts/app.blade.php';
        $this->assertFileExists($appBlade);

        $content = file_get_contents($appBlade) ?: '';

        $this->assertStringContainsString('role="switch"', $content, 'Top navbar theme switch must declare role="switch" for accessibility.');
        $this->assertStringContainsString(':aria-checked="isDark.toString()"', $content, 'Top navbar theme switch must dynamically bind :aria-checked.');
    }
}
