<?php

declare(strict_types=1);

namespace App\Platform\Configuration;

use App\Platform\Authorization\Scope;
use InvalidArgumentException;

/**
 * Enterprise Scoped Typed Configuration Registry (EXT-02, ADR-112).
 */
class TypedConfigRegistry
{
    /**
     * @var array<string, ConfigDefinition>
     */
    private static array $definitions = [];

    private static bool $initialized = false;

    /**
     * Initializes core canonical setting definitions.
     */
    public static function boot(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$definitions = [];

        self::register(new ConfigDefinition(
            key: 'pos.allow_negative_stock',
            type: 'bool',
            default: false,
            scope: Scope::BRANCH,
            ownerModule: 'Sales',
            description: 'Mengizinkan penjualan saat stok fisik 0 atau negatif'
        ));

        self::register(new ConfigDefinition(
            key: 'pos.auto_lock_minutes',
            type: 'int',
            default: 15,
            scope: Scope::BRANCH,
            ownerModule: 'Sales',
            description: 'Batas waktu idle sebelum POS terkunci otomatis'
        ));

        self::register(new ConfigDefinition(
            key: 'pos.require_shift_open',
            type: 'bool',
            default: true,
            scope: Scope::BRANCH,
            ownerModule: 'Sales',
            description: 'Wajibkan pembukaan shift kasir sebelum memproses transaksi'
        ));

        self::register(new ConfigDefinition(
            key: 'tax.default_ppn_rate',
            type: 'decimal',
            default: '11.00',
            scope: Scope::COMPANY,
            ownerModule: 'Finance',
            description: 'Tarif PPN standar CoreTax DJP'
        ));

        self::register(new ConfigDefinition(
            key: 'pricing.reservation_ttl_minutes',
            type: 'int',
            default: 30,
            scope: Scope::TENANT,
            ownerModule: 'Pricing',
            description: 'Masa kedaluwarsa reservasi diskon keranjang belanja'
        ));

        self::register(new ConfigDefinition(
            key: 'finance.enforce_maker_checker',
            type: 'bool',
            default: true,
            scope: Scope::TENANT,
            ownerModule: 'Finance',
            description: 'Wajibkan pemisahan tugas (SoD) antara pemohon dan penyetuju'
        ));

        self::register(new ConfigDefinition(
            key: 'inventory.default_costing_method',
            type: 'string',
            default: 'MOVING_AVERAGE',
            scope: Scope::COMPANY,
            ownerModule: 'Inventory',
            description: 'Metode valuasi biaya persediaan (FIFO / MOVING_AVERAGE)'
        ));

        self::$initialized = true;
    }

    /**
     * Registers a configuration definition.
     */
    public static function register(ConfigDefinition $definition): void
    {
        self::$definitions[$definition->key] = $definition;
    }

    public static function has(string $key): bool
    {
        self::boot();

        return isset(self::$definitions[$key]);
    }

    public static function get(string $key): ConfigDefinition
    {
        self::boot();

        if (! isset(self::$definitions[$key])) {
            throw new InvalidArgumentException("Unknown configuration key: '{$key}'");
        }

        return self::$definitions[$key];
    }

    /**
     * @return array<string, ConfigDefinition>
     */
    public static function all(): array
    {
        self::boot();

        return self::$definitions;
    }

    public static function reset(): void
    {
        self::$definitions = [];
        self::$initialized = false;
    }
}
