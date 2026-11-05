<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Services;

use App\Models\ProductUnitConversion;
use InvalidArgumentException;
use RuntimeException;

/**
 * UnitConversionService — Mesin konversi satuan ukuran (UOM) presisi tinggi berdasar bcmath.
 *
 * Menerapkan:
 * - Kalkulasi arbitrer presisi menggunakan ekstensi bcmath (tanpa floating point bug).
 * - Konversi bolak-balik (round-trip) langsung maupun bertingkat (multi-step transitive conversion).
 * - Presisi desimal minimum 4 digit desimal (scale >= 4).
 * - Dukungan terhadap pohon konversi UOM produk.
 */
class UnitConversionService
{
    public const DEFAULT_SCALE = 4;

    public const INTERNAL_CALCULATION_SCALE = 6;

    /**
     * Konversi nilai kuantitas langsung menggunakan faktor pengali (multiplier).
     *
     * @param  string|float|int  $quantity  Jumlah yang akan dikonversi
     * @param  string|float|int  $multiplier  Faktor pengali (1 From = Multiplier To)
     * @param  bool  $reverse  Jika true, konversi balik (To -> From, dibagi multiplier)
     * @param  int  $scale  Presisi desimal hasil (default 4)
     * @return numeric-string Kuantitas hasil dalam format string desimal presisi
     */
    public function convertDirect(
        string|float|int $quantity,
        string|float|int $multiplier,
        bool $reverse = false,
        int $scale = self::DEFAULT_SCALE
    ): string {
        $qtyStr = (string) $quantity;
        $mulStr = (string) $multiplier;

        if (! is_numeric($qtyStr) || ! is_numeric($mulStr)) {
            throw new InvalidArgumentException('Kuantitas dan faktor pengali harus berupa nilai numerik.');
        }

        if (bccomp($mulStr, '0', self::INTERNAL_CALCULATION_SCALE) === 0) {
            throw new InvalidArgumentException('Faktor pengali konversi satuan tidak boleh bernilai nol.');
        }

        if (! $reverse) {
            // Forward conversion: Quantity * Multiplier
            /** @var numeric-string $result */
            $result = bcmul($qtyStr, $mulStr, $scale);
        } else {
            // Reverse conversion: Quantity / Multiplier
            /** @var numeric-string $result */
            $result = bcdiv($qtyStr, $mulStr, $scale);
        }

        return $result;
    }

    /**
     * Konversi kuantitas antar dua unit ukuran untuk suatu tenant dan produk tertentu.
     * Mampu menyelesaikan rantai konversi bertingkat (misal: Sak -> Kg -> Gram).
     *
     * @param  int  $tenantId  Tenant ID
     * @param  int  $fromUomId  ID UOM asal
     * @param  int  $toUomId  ID UOM tujuan
     * @param  string|float|int  $quantity  Kuantitas yang dikonversi
     * @param  int|null  $productId  ID produk (opsional jika konversi berlaku umum/produk)
     * @param  int  $scale  Presisi desimal hasil
     * @return string Kuantitas hasil konversi
     *
     * @throws RuntimeException Jika tidak ditemukan jalur konversi antara kedua UOM.
     */
    public function convertBetweenUoms(
        int $tenantId,
        int $fromUomId,
        int $toUomId,
        string|float|int $quantity,
        ?int $productId = null,
        int $scale = self::DEFAULT_SCALE
    ): string {
        $qtyStr = (string) $quantity;

        if (! is_numeric($qtyStr)) {
            throw new InvalidArgumentException('Kuantitas harus berupa nilai numerik.');
        }

        // Jika satuan sama, kembalikan dengan skala terformat
        if ($fromUomId === $toUomId) {
            /** @var numeric-string $formatted */
            $formatted = bcadd($qtyStr, '0', $scale);

            return $formatted;
        }

        // Ambil seluruh aturan konversi yang relevan untuk tenant (dan produk jika ada)
        $query = ProductUnitConversion::withoutGlobalScopes()
            ->where('tenant_id', $tenantId);

        if ($productId !== null) {
            $query->where(function ($q) use ($productId) {
                $q->where('product_id', $productId)
                    ->orWhereNull('product_id');
            });
        }

        $conversions = $query->get();

        // 1. Cek langsung (Direct Match)
        foreach ($conversions as $conv) {
            $factor = (string) ($conv->conversion_factor ?? $conv->multiplier ?? '1');

            if ((int) $conv->from_uom_id === $fromUomId && (int) $conv->to_uom_id === $toUomId) {
                return $this->convertDirect($qtyStr, $factor, false, $scale);
            }

            if ((int) $conv->to_uom_id === $fromUomId && (int) $conv->from_uom_id === $toUomId) {
                return $this->convertDirect($qtyStr, $factor, true, $scale);
            }
        }

        // 2. Bangun graph konversi untuk Multi-Step Transitive Conversion (BFS)
        /** @var array<int, array<int, array{neighbor: int, factor: string, is_reverse: bool}>> $graph */
        $graph = [];

        foreach ($conversions as $conv) {
            $uomA = (int) $conv->from_uom_id;
            $uomB = (int) $conv->to_uom_id;
            $factor = (string) ($conv->conversion_factor ?? $conv->multiplier ?? '1');

            // Forward: uomA -> uomB (kali factor)
            $graph[$uomA][] = ['neighbor' => $uomB, 'factor' => $factor, 'is_reverse' => false];
            // Backward: uomB -> uomA (bagi factor)
            $graph[$uomB][] = ['neighbor' => $uomA, 'factor' => $factor, 'is_reverse' => true];
        }

        $path = $this->findConversionPath($graph, $fromUomId, $toUomId);

        if ($path === null) {
            throw new RuntimeException("Tidak ditemukan jalur konversi antara UOM ID {$fromUomId} dan UOM ID {$toUomId}.");
        }

        // Eksekusi kalkulasi rantai bertingkat pada skala internal tinggi untuk menjaga akurasi
        $currentQty = $qtyStr;
        foreach ($path as $step) {
            $currentQty = $this->convertDirect(
                $currentQty,
                $step['factor'],
                $step['is_reverse'],
                self::INTERNAL_CALCULATION_SCALE
            );
        }

        // Bulatkan/format hasil akhir ke skala presisi yang diminta
        /** @var numeric-string $finalQty */
        $finalQty = bcadd($currentQty, '0', $scale);

        return $finalQty;
    }

    /**
     * Cari jalur konversi terpendek menggunakan algoritma Breadth-First Search (BFS).
     *
     * @param  array<int, array<int, array{neighbor: int, factor: string, is_reverse: bool}>>  $graph
     * @return array<int, array{factor: string, is_reverse: bool}>|null
     */
    private function findConversionPath(array $graph, int $start, int $target): ?array
    {
        $queue = [[$start, []]];
        $visited = [$start => true];

        while (! empty($queue)) {
            [$currentNode, $currentPath] = array_shift($queue);

            if ($currentNode === $target) {
                return $currentPath;
            }

            foreach ($graph[$currentNode] ?? [] as $edge) {
                $neighbor = $edge['neighbor'];
                if (! isset($visited[$neighbor])) {
                    $visited[$neighbor] = true;
                    $newPath = $currentPath;
                    $newPath[] = [
                        'factor' => $edge['factor'],
                        'is_reverse' => $edge['is_reverse'],
                    ];
                    $queue[] = [$neighbor, $newPath];
                }
            }
        }

        return null;
    }

    /**
     * Penjumlahan presisi tinggi (bcadd).
     *
     * @param  numeric-string  $qty1
     * @param  numeric-string  $qty2
     */
    public function add(string $qty1, string $qty2, int $scale = self::DEFAULT_SCALE): string
    {
        return bcadd($qty1, $qty2, $scale);
    }

    /**
     * Pengurangan presisi tinggi (bcsub).
     *
     * @param  numeric-string  $qty1
     * @param  numeric-string  $qty2
     */
    public function subtract(string $qty1, string $qty2, int $scale = self::DEFAULT_SCALE): string
    {
        return bcsub($qty1, $qty2, $scale);
    }

    /**
     * Perbandingan kuantitas presisi tinggi (bccomp):
     * Return: 0 jika sama, 1 jika qty1 > qty2, -1 jika qty1 < qty2.
     *
     * @param  numeric-string  $qty1
     * @param  numeric-string  $qty2
     */
    public function compare(string $qty1, string $qty2, int $scale = self::DEFAULT_SCALE): int
    {
        return bccomp($qty1, $qty2, $scale);
    }
}
