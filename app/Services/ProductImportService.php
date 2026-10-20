<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\TaxClass;
use App\Models\UnitOfMeasure;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductImportService
{
    public const MAX_IMPORT_ROWS = 500;

    public function __construct(
        protected MenuCacheService $menuCache,
        protected EntitlementService $entitlementService,
        protected ?AuditTrailService $auditTrail = null
    ) {
        $this->auditTrail ??= app(AuditTrailService::class);
    }

    /**
     * Membaca dan mengimpor file CSV produk.
     *
     * @param  UploadedFile|string  $file
     * @param  bool  $strictMode  Jika true, rollback jika ada satu baris gagal
     * @return array{total_rows: int, imported_count: int, failed_count: int, errors: array, products: array}
     */
    public function importCsv($file, ?int $tenantId = null, bool $strictMode = false): array
    {
        $tenantId = $tenantId ?? app(TenantContext::class)->requireTenantId();

        $filePath = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        if (! file_exists($filePath) || ! is_readable($filePath)) {
            throw new \InvalidArgumentException('File impor CSV tidak dapat dibaca.');
        }

        $handle = fopen($filePath, 'r');
        if (! $handle) {
            throw new \RuntimeException('Gagal membuka file CSV.');
        }

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);
            throw new \InvalidArgumentException('File CSV kosong atau header tidak valid.');
        }

        // Normalisasi header (lowercase, trim, snake_case)
        $normalizedHeader = array_map(function ($col) {
            $cleaned = trim((string) $col);
            $cleaned = preg_replace('/[\x{FEFF}]/u', '', $cleaned); // Hapus BOM UTF-8

            return strtolower(str_replace([' ', '-'], '_', $cleaned));
        }, $header);

        $rows = [];
        $rowNumber = 1;

        while (($data = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if (count($data) === 1 && $data[0] === null) {
                continue; // Baris kosong
            }

            if (count($rows) >= self::MAX_IMPORT_ROWS) {
                fclose($handle);
                throw new DomainException('Jumlah baris impor melebihi batas maksimum ('.self::MAX_IMPORT_ROWS.' baris).');
            }

            $rowAssoc = [];
            foreach ($normalizedHeader as $idx => $key) {
                $rowAssoc[$key] = $data[$idx] ?? null;
            }
            $rowAssoc['_row_number'] = $rowNumber;
            $rows[] = $rowAssoc;
        }

        fclose($handle);

        return $this->importRows($rows, $tenantId, $strictMode);
    }

    /**
     * Memproses sekumpulan baris impor secara tervalidasi dan aman.
     *
     * @param  array<array<string, mixed>>  $rows
     * @return array{total_rows: int, imported_count: int, failed_count: int, errors: array, products: array}
     */
    public function importRows(array $rows, int $tenantId, bool $strictMode = false): array
    {
        $totalRows = count($rows);
        if ($totalRows > self::MAX_IMPORT_ROWS) {
            throw new DomainException('Jumlah baris impor melebihi batas maksimum ('.self::MAX_IMPORT_ROWS.' baris).');
        }

        $importedProducts = [];
        $errors = [];

        // Preload relasi katalog tenant untuk efisiensi
        $categoriesByName = Category::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->pluck('id', 'category_name')
            ->mapWithKeys(fn ($id, $name) => [strtolower((string) $name) => $id]);

        $brandsByName = Brand::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [strtolower((string) $name) => $id]);

        $uomsByCode = UnitOfMeasure::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->pluck('id', 'code')
            ->mapWithKeys(fn ($id, $code) => [strtolower((string) $code) => $id]);

        $taxClassesByCode = TaxClass::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->pluck('id', 'code')
            ->mapWithKeys(fn ($id, $code) => [strtolower((string) $code) => $id]);

        $existingSkus = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('product_code')
            ->pluck('product_code')
            ->map(fn ($s) => strtolower((string) $s))
            ->flip()
            ->all();

        $existingBarcodes = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('barcode')
            ->pluck('barcode')
            ->map(fn ($b) => strtolower((string) $b))
            ->flip()
            ->all();

        $processedSkusInBatch = [];
        $processedBarcodesInBatch = [];

        $execute = function () use (
            $rows,
            $tenantId,
            $categoriesByName,
            $brandsByName,
            $uomsByCode,
            $taxClassesByCode,
            &$existingSkus,
            &$existingBarcodes,
            &$processedSkusInBatch,
            &$processedBarcodesInBatch,
            &$importedProducts,
            &$errors,
            $strictMode
        ) {
            foreach ($rows as $index => $row) {
                $rowNum = $row['_row_number'] ?? ($index + 2);
                $rowErrors = [];

                // Sanitasi formula dari seluruh input teks (kecuali nilai numerik murni seperti bilangan negatif)
                foreach ($row as $k => $v) {
                    if (is_string($v) && ! is_numeric($v) && preg_match('/^[\=\+\-\@\t\r]/', $v)) {
                        $row[$k] = ltrim($v, " =+-@\t\r");
                    }
                }

                $name = trim((string) ($row['product_name'] ?? $row['name'] ?? ''));
                if ($name === '') {
                    $rowErrors[] = 'Nama produk wajib diisi.';
                }

                $priceRaw = $row['price'] ?? $row['product_price'] ?? null;
                if ($priceRaw === null || ! is_numeric($priceRaw) || (float) $priceRaw < 0) {
                    $rowErrors[] = 'Harga produk harus berupa angka bernilai >= 0.';
                }

                $sku = trim((string) ($row['sku'] ?? $row['product_code'] ?? ''));
                if ($sku !== '') {
                    $skuLower = strtolower($sku);
                    if (isset($existingSkus[$skuLower]) || isset($processedSkusInBatch[$skuLower])) {
                        $rowErrors[] = "SKU '{$sku}' sudah digunakan di tenant ini.";
                    }
                }

                $barcode = trim((string) ($row['barcode'] ?? ''));
                if ($barcode !== '') {
                    $barcodeLower = strtolower($barcode);
                    if (isset($existingBarcodes[$barcodeLower]) || isset($processedBarcodesInBatch[$barcodeLower])) {
                        $rowErrors[] = "Barcode '{$barcode}' sudah digunakan di tenant ini.";
                    }
                }

                // Resolusi category
                $categoryId = null;
                $hasExplicitCategory = false;
                if (! empty($row['category_id']) && is_numeric($row['category_id'])) {
                    $hasExplicitCategory = true;
                    $catExists = Category::withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)
                        ->where('id', (int) $row['category_id'])
                        ->exists();
                    if ($catExists) {
                        $categoryId = (int) $row['category_id'];
                    } else {
                        $rowErrors[] = "Category ID '{$row['category_id']}' tidak ditemukan atau milik tenant lain.";
                    }
                } elseif (! empty($row['category']) || ! empty($row['category_name'])) {
                    $hasExplicitCategory = true;
                    $catName = strtolower(trim((string) ($row['category'] ?? $row['category_name'])));
                    if (isset($categoriesByName[$catName])) {
                        $categoryId = $categoriesByName[$catName];
                    } else {
                        $rowErrors[] = "Kategori '{$catName}' tidak ditemukan di tenant ini.";
                    }
                }

                // Jika tidak ada kolom kategori yang ditentukan dan belum error, fallback ke kategori default tenant
                if (! $hasExplicitCategory && empty($rowErrors)) {
                    $defaultCatId = $categoriesByName->first();
                    if (! $defaultCatId) {
                        $defaultCategory = Category::withoutGlobalScopes()
                            ->where('tenant_id', $tenantId)
                            ->first();

                        if (! $defaultCategory) {
                            $defaultCategory = Category::create([
                                'tenant_id' => $tenantId,
                                'category_name' => 'General',
                                'is_active' => true,
                            ]);
                        }
                        $defaultCatId = $defaultCategory->id;
                        $categoriesByName[strtolower($defaultCategory->category_name)] = $defaultCatId;
                    }
                    $categoryId = $defaultCatId;
                }

                // Resolusi brand
                $brandId = null;
                if (! empty($row['brand_id']) && is_numeric($row['brand_id'])) {
                    $brandExists = Brand::withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)
                        ->where('id', (int) $row['brand_id'])
                        ->exists();
                    if ($brandExists) {
                        $brandId = (int) $row['brand_id'];
                    } else {
                        $rowErrors[] = "Brand ID '{$row['brand_id']}' tidak valid atau milik tenant lain.";
                    }
                } elseif (! empty($row['brand']) || ! empty($row['brand_name'])) {
                    $bName = strtolower(trim((string) ($row['brand'] ?? $row['brand_name'])));
                    if (isset($brandsByName[$bName])) {
                        $brandId = $brandsByName[$bName];
                    } else {
                        $rowErrors[] = "Brand '{$bName}' tidak ditemukan di tenant ini.";
                    }
                }

                // Resolusi base UoM
                $baseUomId = null;
                if (! empty($row['base_uom_id']) && is_numeric($row['base_uom_id'])) {
                    $uomExists = UnitOfMeasure::withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)
                        ->where('id', (int) $row['base_uom_id'])
                        ->exists();
                    if ($uomExists) {
                        $baseUomId = (int) $row['base_uom_id'];
                    } else {
                        $rowErrors[] = "Base UoM ID '{$row['base_uom_id']}' tidak valid atau milik tenant lain.";
                    }
                } elseif (! empty($row['base_uom']) || ! empty($row['uom_code'])) {
                    $uCode = strtolower(trim((string) ($row['base_uom'] ?? $row['uom_code'])));
                    if (isset($uomsByCode[$uCode])) {
                        $baseUomId = $uomsByCode[$uCode];
                    } else {
                        $rowErrors[] = "Satuan Ukur (UoM) '{$uCode}' tidak ditemukan di tenant ini.";
                    }
                }

                // Resolusi Tax Class
                $taxClassId = null;
                if (! empty($row['tax_class_id']) && is_numeric($row['tax_class_id'])) {
                    $tcExists = TaxClass::withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)
                        ->where('id', (int) $row['tax_class_id'])
                        ->exists();
                    if ($tcExists) {
                        $taxClassId = (int) $row['tax_class_id'];
                    } else {
                        $rowErrors[] = "Tax Class ID '{$row['tax_class_id']}' tidak valid atau milik tenant lain.";
                    }
                } elseif (! empty($row['tax_class']) || ! empty($row['tax_class_code'])) {
                    $tcCode = strtolower(trim((string) ($row['tax_class'] ?? $row['tax_class_code'])));
                    if (isset($taxClassesByCode[$tcCode])) {
                        $taxClassId = $taxClassesByCode[$tcCode];
                    } else {
                        $rowErrors[] = "Klasifikasi Pajak '{$tcCode}' tidak ditemukan di tenant ini.";
                    }
                }

                // Entitlement limit check
                try {
                    $this->entitlementService->assertCanCreateProduct($tenantId);
                } catch (DomainException $de) {
                    $rowErrors[] = $de->getMessage();
                }

                if (! empty($rowErrors)) {
                    $errors[] = [
                        'row' => $rowNum,
                        'name' => $name ?: 'N/A',
                        'errors' => $rowErrors,
                    ];
                    if ($strictMode) {
                        throw ValidationException::withMessages([
                            'import' => "Gagal pada baris {$rowNum}: ".implode(', ', $rowErrors),
                        ]);
                    }

                    continue;
                }

                // Validasi product_type
                $productType = strtolower(trim((string) ($row['product_type'] ?? 'standard')));
                if ($productType === 'physical') {
                    $productType = 'standard';
                }
                if (! in_array($productType, ['standard', 'service', 'non_inventory', 'bundle'], true)) {
                    $productType = 'standard';
                }

                $status = strtolower(trim((string) ($row['status'] ?? 'active')));
                if (! in_array($status, ['draft', 'active', 'inactive', 'discontinued'], true)) {
                    $status = 'active';
                }

                $trackInventory = ! in_array($productType, ['service', 'non_inventory', 'bundle'], true);
                if (isset($row['track_inventory'])) {
                    $trackInventory = filter_var($row['track_inventory'], FILTER_VALIDATE_BOOLEAN) && $trackInventory;
                }

                $stock = $trackInventory ? (int) ($row['stock'] ?? 0) : 0;
                $cost = (float) ($row['standard_cost'] ?? $row['cost'] ?? 0.0);
                $isTaxInclusive = filter_var($row['is_tax_inclusive'] ?? false, FILTER_VALIDATE_BOOLEAN);

                $product = Product::create([
                    'tenant_id' => $tenantId,
                    'product_name' => $name,
                    'product_code' => $sku !== '' ? $sku : null,
                    'barcode' => $barcode !== '' ? $barcode : null,
                    'category_id' => $categoryId,
                    'brand_id' => $brandId,
                    'base_uom_id' => $baseUomId,
                    'tax_class_id' => $taxClassId,
                    'is_tax_inclusive' => $isTaxInclusive,
                    'product_type' => $productType,
                    'status' => $status,
                    'is_active' => ($status === 'active'),
                    'track_inventory' => $trackInventory,
                    'product_price' => (float) $priceRaw,
                    'standard_cost' => $cost,
                    'costing_method' => strtolower((string) ($row['costing_method'] ?? 'standard')),
                    'stock' => $stock,
                ]);

                if ($sku !== '') {
                    $processedSkusInBatch[strtolower($sku)] = true;
                }
                if ($barcode !== '') {
                    $processedBarcodesInBatch[strtolower($barcode)] = true;
                }

                $importedProducts[] = $product;
            }
        };

        if ($strictMode) {
            DB::transaction($execute);
        } else {
            // Non-strict: commit baris yang valid
            DB::transaction($execute);
        }

        if (count($importedProducts) > 0) {
            $this->menuCache->flush($tenantId);
            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'catalog.import',
                description: 'Mengimpor '.count($importedProducts).' produk ke katalog.',
                metadata: [
                    'imported_count' => count($importedProducts),
                    'failed_count' => count($errors),
                ]
            );
        }

        return [
            'total_rows' => $totalRows,
            'imported_count' => count($importedProducts),
            'failed_count' => count($errors),
            'errors' => $errors,
            'products' => $importedProducts,
        ];
    }
}
