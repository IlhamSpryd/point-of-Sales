<?php

declare(strict_types=1);

namespace App\Modules\Finance\Contracts;

use App\Modules\Finance\DTOs\TaxInvoiceDocumentDto;
use App\Modules\Sales\Models\CustomerInvoice;

interface TaxIntegrationGatewayInterface
{
    /**
     * Memvalidasi format NPWP Indonesia (15 digit format lama atau 16 digit format NIK/CoreTax).
     */
    public function validateTaxId(string $taxId): bool;

    /**
     * Memformat string NPWP angka mentah menjadi format resmi DJP (XX.XXX.XXX.X-XXX.XXX atau format 16 digit).
     */
    public function formatTaxId(string $taxId): string;

    /**
     * Menghasilkan atau mengalokasikan Nomor Seri Faktur Pajak (NSFP) standar DJP.
     * Format: [Kode Transaksi 2 digit][Status Pengganti 1 digit].[Kode Cabang 3 digit]-[Tahun 2 digit].[Nomor Urut 8 digit]
     * Contoh: 010.000-26.00000001
     */
    public function generateTaxInvoiceSerialNumber(
        int $tenantId,
        int $year,
        string $transactionType = '01',
        bool $isReplacement = false
    ): string;

    /**
     * Mengonversi entitas CustomerInvoice menjadi DTO faktur pajak standar.
     */
    public function buildDocumentFromCustomerInvoice(CustomerInvoice $invoice): TaxInvoiceDocumentDto;

    /**
     * Menghasilkan teks CSV e-Faktur resmi DJP siap impor (memuat baris FK, LT, dan OF).
     */
    public function exportCsv(
        int $tenantId,
        int $taxPeriodMonth,
        int $taxPeriodYear,
        string $direction = 'outbound'
    ): string;

    /**
     * Menghubungkan atau mensimulasikan sinkronisasi faktur pajak ke API CoreTax PJAP berlisensi.
     *
     * @return array<string, mixed>
     */
    public function syncWithCoreTax(TaxInvoiceDocumentDto $document): array;
}

