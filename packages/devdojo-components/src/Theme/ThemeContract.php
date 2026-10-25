<?php

namespace DevDojo\Components\Theme;

/**
 * The portable styling contract shared by DevDojo Components, Designer,
 * and Projects. Products may offer any palette they like; compatibility is
 * determined by this stable id/version and the semantic tokens below.
 */
final class ThemeContract
{
    public const ID = 'devdojo.theme';

    public const VERSION = 1;

    public const REFERENCE = self::ID.'@'.self::VERSION;

    public const COLOR_TOKENS = [
        'background', 'foreground',
        'card', 'card-foreground',
        'popover', 'popover-foreground',
        'primary', 'primary-foreground',
        'secondary', 'secondary-foreground',
        'muted', 'muted-foreground',
        'accent', 'accent-foreground',
        'destructive', 'destructive-foreground',
        'border', 'input', 'ring',
    ];

    public const RADIUS_TOKENS = [
        'radius-small',
        'radius-medium',
        'radius-large',
    ];

    public const FONT_TOKENS = [
        'font-sans',
        'font-display',
    ];

    public static function descriptor(): array
    {
        return [
            'id' => self::ID,
            'version' => self::VERSION,
        ];
    }

    public static function supports(mixed $contract): bool
    {
        return is_array($contract)
            && ($contract['id'] ?? null) === self::ID
            && (int) ($contract['version'] ?? 0) === self::VERSION;
    }
}
