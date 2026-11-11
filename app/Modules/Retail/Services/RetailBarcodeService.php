<?php

declare(strict_types=1);

namespace App\Modules\Retail\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RetailBarcodeService
{
    public function __construct(
        protected AuditTrailService $auditTrail,
        protected TenantContext $tenantContext
    ) {}

    /**
     * Memvalidasi algoritma check digit resmi EAN-13.
     */
    public function validateEan13Checksum(string $barcode): bool
    {
        $clean = trim($barcode);
        if (! preg_match('/^\d{13}$/', $clean)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $clean[$i];
            // Digit ganjil (1st, 3rd, 5th, dst. -> index 0, 2, 4...) bobot 1
            // Digit genap (2nd, 4th, 6th, dst. -> index 1, 3, 5...) bobot 3
            $weight = ($i % 2 === 0) ? 1 : 3;
            $sum += $digit * $weight;
        }

        $remainder = $sum % 10;
        $calculatedCheckDigit = ($remainder === 0) ? 0 : (10 - $remainder);
        $actualCheckDigit = (int) $clean[12];

        return $calculatedCheckDigit === $actualCheckDigit;
    }

    /**
     * Alias untuk validasi checksum EAN-13.
     */
    public function validateEan13(string $barcode): bool
    {
        return $this->validateEan13Checksum($barcode);
    }

    /**
     * Memeriksa apakah barcode merupakan barcode timbangan elektronik (variable measure / scale barcode).
     * Standar GS1 Retail: Prefix 02 (weight-embedded) atau 20 (price-embedded) dengan panjang 13 digit numerik.
     */
    public function isScaleBarcode(string $barcode): bool
    {
        $clean = trim($barcode);
        if (! preg_match('/^\d{13}$/', $clean)) {
            return false;
        }

        $prefix = substr($clean, 0, 2);
        $validPrefixes = ['02', '20', '21', '22', '23', '24', '25', '26', '27', '28', '29'];

        return in_array($prefix, $validPrefixes, true);
    }

    /**
     * Mengurai barcode timbangan digital supermarket (TASK-P2-02).
     *
     * @return array{is_scale: bool, prefix: string, item_code: string, weight_grams: int, weight_kg: numeric-string, embedded_price: int, check_digit: int}|null
     */
    public function parseScaleBarcode(string $barcode): ?array
    {
        $clean = trim($barcode);
        if (! $this->isScaleBarcode($clean)) {
            return null;
        }

        $prefix = substr($clean, 0, 2);
        $itemCode = substr($clean, 2, 5); // 5 digit kode PLU / produk
        $dataPart = substr($clean, 7, 5); // 5 digit bobot atau harga
        $checkDigit = (int) substr($clean, 12, 1);

        $dataInt = (int) $dataPart;
        // Konversi ke bobot kilogram dengan presisi 3 desimal
        $weightKg = bcdiv((string) $dataInt, '1000', 3);

        return [
            'is_scale' => true,
            'prefix' => $prefix,
            'item_code' => $itemCode,
            'weight_grams' => $dataInt,
            'weight_kg' => $weightKg,
            'embedded_price' => $dataInt,
            'check_digit' => $checkDigit,
        ];
    }

    /**
     * Menghasilkan barcode retail standar EAN-13 internal (prefix '20').
     */
    public function generateInternalBarcode(): string
    {
        $prefix = '20';
        $random = str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
        $payload = $prefix . $random;

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $payload[$i];
            $weight = ($i % 2 === 0) ? 1 : 3;
            $sum += $digit * $weight;
        }

        $remainder = $sum % 10;
        $checkDigit = ($remainder === 0) ? 0 : (10 - $remainder);

        return $payload . $checkDigit;
    }

    /**
     * Memasangkan barcode ke varian produk dengan validasi keunikan per-tenant.
     */
    public function assignBarcodeToVariant(int $variantId, string $barcode): void
    {
        $cleanBarcode = trim($barcode);
        if (! $this->validateEan13Checksum($cleanBarcode)) {
            throw new DomainException("Barcode {$cleanBarcode} tidak valid menurut algoritma EAN-13.");
        }

        $tenantId = (int) $this->tenantContext->getTenantId();
        $variant = ProductVariant::where('tenant_id', $tenantId)->findOrFail($variantId);

        $exists = ProductVariant::where('tenant_id', $tenantId)
            ->where('id', '!=', $variantId)
            ->where('barcode', $cleanBarcode)
            ->exists();

        if ($exists) {
            throw new DomainException("Barcode {$cleanBarcode} sudah digunakan oleh varian produk lain.");
        }

        $variant->update(['barcode' => $cleanBarcode]);
    }

    /**
     * Pencarian instan scan-to-cart berbasis barcode varian/produk dengan validasi assortment cabang aktif.
     * Dijamin beroperasi pada performa sub-50ms (T-RTL-01).
     *
     * @return array{product: Product, variant: ?ProductVariant, price: float, barcode: string, execution_time_ms: float, is_available: bool}
     *
     * @throws DomainException
     */
    public function resolveBarcode(int $tenantId, int $storeId, string $barcode): array
    {
        $startTime = hrtime(true);
        $cleanBarcode = trim($barcode);

        if (empty($cleanBarcode)) {
            throw new InvalidArgumentException('Barcode tidak boleh kosong.');
        }

        // 1. Cari di ProductVariant terlebih dahulu (spesifik ukuran / warna ritel)
        $variant = ProductVariant::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('barcode', $cleanBarcode)
            ->with(['product'])
            ->first();

        $product = null;
        if ($variant && $variant->product instanceof Product) {
            $product = $variant->product;
        } else {
            // 2. Jika bukan varian, cari langsung di tabel Products
            $product = Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('barcode', $cleanBarcode)
                ->first();
        }

        if (! $product instanceof Product) {
            throw new DomainException("Barcode {$cleanBarcode} tidak terdaftar dalam katalog produk.");
        }

        // 3. Validasi Assortment Cabang Aktif (T-RTL-02)
        $assortmentQuery = DB::table('store_product_assortments')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('product_id', $product->id);

        if ($variant) {
            $assortmentQuery->where(function ($q) use ($variant) {
                $q->where('product_variant_id', $variant->id)
                    ->orWhereNull('product_variant_id');
            });
        }

        $assortment = $assortmentQuery->first();

        // Jika ada record assortment dan ditandai tidak tersedia, atau belum di-assort
        if ($assortment && ! (bool) $assortment->is_available) {
            throw new DomainException("Produk \"{$product->product_name}\" tidak dijual di cabang ini.");
        }

        // Jika cabang memiliki konfigurasi assortment ketat (ada produk lain di-assort, tapi produk ini tidak terdaftar)
        $hasBranchAssortments = DB::table('store_product_assortments')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->exists();

        if ($hasBranchAssortments && ! $assortment) {
            throw new DomainException("Produk \"{$product->product_name}\" tidak dijual di cabang ini.");
        }

        // 4. Resolusi Harga Jual Toko
        $finalPrice = (float) $product->product_price;
        if ($variant) {
            $variantPrice = $variant->price_override ?? $variant->price ?? null;
            if ($variantPrice !== null && (float) $variantPrice > 0) {
                $finalPrice = (float) $variantPrice;
            }
        }

        if ($assortment && $assortment->store_price_override !== null && (float) $assortment->store_price_override > 0) {
            $finalPrice = (float) $assortment->store_price_override;
        }

        $elapsedMs = (hrtime(true) - $startTime) / 1e6;

        return [
            'product' => $product,
            'variant' => $variant,
            'price' => $finalPrice,
            'barcode' => $cleanBarcode,
            'execution_time_ms' => round($elapsedMs, 2),
            'is_available' => true,
        ];
    }
}
