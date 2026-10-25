<?php

use DevDojo\Components\Components;
use Symfony\Component\Finder\Finder;

/**
 * Enforces the styling rules in this package's CLAUDE.md so components stay
 * fully themeable. See "Sanctioned color exceptions" there for context.
 */
dataset('component blades', function () {
    $files = [];

    foreach (Finder::create()->files()->name('*.blade.php')->in(Components::sourcePath()) as $file) {
        $files[$file->getRelativePathname()] = $file->getRealPath();
    }

    return $files;
});

it('uses theme radius tokens only — no bare or Tailwind-default radii', function (string $file) {
    $contents = file_get_contents($file);

    // Match every rounded utility (incl. directional, e.g. rounded-t-medium).
    preg_match_all('/\brounded(?:-[a-z0-9]+){0,2}\b/', $contents, $matches);

    $directions = 't|b|l|r|s|e|tl|tr|bl|br|ss|se|es|ee';

    $violations = collect($matches[0])->unique()->reject(function ($class) use ($directions) {
        return $class === 'rounded-full'
            || preg_match('/^rounded(?:-(?:'.$directions.'))?-(?:small|medium|large)$/', $class);
    })->values()->all();

    expect($violations)->toBe([], 'Use rounded-small|medium|large|full (optionally directional). Found: '.implode(', ', $violations));
})->with('component blades');

it('uses color tokens — no gray/neutral/stone/zinc/slate families', function (string $file) {
    $contents = file_get_contents($file);

    preg_match_all(
        '/\b(?:text|bg|border|ring|fill|stroke|placeholder|from|via|to|divide|outline|ring-offset|shadow|accent|caret|decoration)-(?:gray|neutral|stone|zinc|slate)-\d+\b/',
        $contents,
        $matches
    );

    expect(array_values(array_unique($matches[0])))
        ->toBe([], 'Use theme color tokens instead of raw gray/neutral/stone/zinc/slate.');
})->with('component blades');
