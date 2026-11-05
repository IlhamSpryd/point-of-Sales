<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * MarginProtectionGuard — Penjaga ambang batas margin minimum keuntungan produk.
 *
 * Mengikuti aturan bisnis BR-PRC-001 & T-PRC-03:
 * Jika penerapan diskon menghasilkan harga jual di bawah HPP (cost_price)
 * ditambah batas minimal margin (default 5%), sistem otomatis menolak diskon
 * kecuali diotorisasi dengan PIN supervisor yang valid.
 */
class MarginProtectionGuard
{
    public const DEFAULT_MIN_MARGIN_PERCENT = 5.0;

    public function __construct() {}

    /**
     * Validasi margin keuntungan terhadap harga jual akhir.
     *
     * @param  float|string  $costPrice  Harga Pokok Penjualan (HPP / Standard Cost)
     * @param  float|string  $finalPrice  Harga jual yang diusulkan setelah diskon
     * @param  string|null  $supervisorPin  PIN otorisasi manajer/supervisor (opsional)
     * @param  float  $minMarginPercent  Ambang batas margin minimum dalam persen (default 5%)
     * @param  int|null  $tenantId  Tenant ID untuk verifikasi supervisor
     * @return bool True jika margin aman atau diotorisasi supervisor
     *
     * @throws ValidationException Jika harga di bawah HPP + margin tanpa otorisasi supervisor valid.
     */
    public function validateMargin(
        float|string $costPrice,
        float|string $finalPrice,
        ?string $supervisorPin = null,
        float $minMarginPercent = self::DEFAULT_MIN_MARGIN_PERCENT,
        ?int $tenantId = null
    ): bool {
        $cost = (float) $costPrice;
        $price = (float) $finalPrice;

        // Hitung batas harga jual minimum = HPP * (1 + margin / 100)
        $marginMultiplier = 1.0 + ($minMarginPercent / 100.0);
        $minimumSellingPrice = $cost * $marginMultiplier;

        // Jika harga jual masih memenuhi batas minimum margin, lolos tanpa supervisor
        if ($price >= $minimumSellingPrice) {
            return true;
        }

        // Jika harga jual di bawah batas minimum margin, wajib otorisasi supervisor
        if ($supervisorPin === null || trim($supervisorPin) === '') {
            throw ValidationException::withMessages([
                'price' => sprintf(
                    'Harga jual Rp %s melanggar batas margin minimum 5%% (HPP Rp %s, Minimum Rp %s). Diperlukan otorisasi PIN supervisor.',
                    number_format($price, 2),
                    number_format($cost, 2),
                    number_format($minimumSellingPrice, 2)
                ),
            ]);
        }

        // Verifikasi supervisor PIN
        if (! $this->verifySupervisorPin($supervisorPin, $tenantId)) {
            throw ValidationException::withMessages([
                'supervisor_pin' => 'PIN supervisor tidak valid. Transaksi harga di bawah HPP ditolak.',
            ]);
        }

        return true;
    }

    /**
     * Verifikasi PIN supervisor / manager di dalam tenant.
     */
    private function verifySupervisorPin(string $pin, ?int $tenantId): bool
    {
        if ($tenantId === null) {
            return false;
        }

        // Cari user di tenant yang memiliki role supervisor/manager/owner/branch manager dengan PIN cocok
        $supervisors = User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereHas('role', fn ($r) => $r->whereIn('name', ['Owner', 'Manager', 'Branch Manager', 'Supervisor']))
            ->whereNotNull('pin_hash')
            ->get();

        foreach ($supervisors as $supervisor) {
            if ($supervisor->pin_hash && Hash::check($pin, (string) $supervisor->pin_hash)) {
                return true;
            }
        }

        return false;
    }
}
