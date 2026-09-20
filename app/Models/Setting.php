<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'description'];

    private const CACHE_TTL = 3600;

    /**
     * Ambil nilai setting dengan casting otomatis sesuai kolom `type`.
     * OPTIMASI: di-cache 1 jam karena setting bisnis (pajak, pembulatan, dll)
     * SANGAT jarang berubah tapi berpotensi dibaca pada SETIAP transaksi kasir.
     * Tanpa cache ini, setiap checkout menambah query DB murni untuk data statis.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember("pos:setting:{$key}", self::CACHE_TTL, function () use ($key, $default) {
            $setting = static::where('key', $key)->first();

            if (! $setting) {
                return $default;
            }

            return match ($setting->type) {
                'integer' => (int) $setting->value,
                'boolean' => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
                'json' => json_decode($setting->value, true),
                default => $setting->value,
            };
        });
    }

    public static function set(string $key, mixed $value, string $type = 'string'): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => is_array($value) ? json_encode($value) : (string) $value, 'type' => $type]
        );

        // WAJIB: hapus cache lama agar perubahan setting langsung berlaku,
        // tidak menunggu TTL 1 jam habis.
        Cache::forget("pos:setting:{$key}");
    }
}
