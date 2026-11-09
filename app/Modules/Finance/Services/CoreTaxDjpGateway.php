<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Contracts\TaxIntegrationGatewayInterface;
use App\Modules\Finance\DTOs\TaxInvoiceDocumentDto;
use App\Modules\Finance\DTOs\TaxInvoiceItemDto;
use App\Modules\Sales\Models\CustomerInvoice;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CoreTaxDjpGateway implements TaxIntegrationGatewayInterface
{
    protected CoreTaxSchemaValidator $validator;

    protected CoreTaxXmlGenerator $xmlGenerator;

    protected CoreTaxJsonGenerator $jsonGenerator;

    public function __construct(
        ?CoreTaxSchemaValidator $validator = null,
        ?CoreTaxXmlGenerator $xmlGenerator = null,
        ?CoreTaxJsonGenerator $jsonGenerator = null
    ) {
        $this->validator = $validator ?? app(CoreTaxSchemaValidator::class);
        $this->xmlGenerator = $xmlGenerator ?? app(CoreTaxXmlGenerator::class);
        $this->jsonGenerator = $jsonGenerator ?? app(CoreTaxJsonGenerator::class);
    }
    /**
     * Memvalidasi format NPWP Indonesia (15 digit format lama atau 16 digit format NIK/CoreTax).
     */
    public function validateTaxId(string $taxId): bool
    {
        $clean = preg_replace('/\D/', '', $taxId);
        if ($clean === null || $clean === '') {
            return false;
        }

        $length = strlen($clean);

        // Kriteria NPWP Indonesia: 15 digit (NPWP konvensional) atau 16 digit (NIK/CoreTax per PMK 112/2022)
        return in_array($length, [15, 16], true);
    }

    /**
     * Memformat string NPWP angka mentah menjadi format resmi DJP.
     * Contoh 15 digit: 01.234.567.8-901.000
     */
    public function formatTaxId(string $taxId): string
    {
        $digits = preg_replace('/\D/', '', $taxId);
        if ($digits === null || $digits === '') {
            return $taxId;
        }

        if (strlen($digits) === 15) {
            return sprintf(
                '%s.%s.%s.%s-%s.%s',
                substr($digits, 0, 2),
                substr($digits, 2, 3),
                substr($digits, 5, 3),
                substr($digits, 8, 1),
                substr($digits, 9, 3),
                substr($digits, 12, 3)
            );
        }

        if (strlen($digits) === 16) {
            return $digits;
        }

        return $taxId;
    }

    /**
     * Menghasilkan Nomor Seri Faktur Pajak (NSFP) standar DJP.
     * Format: [Kode Transaksi 2 digit][Status Pengganti 1 digit].[Kode Cabang 3 digit]-[Tahun 2 digit].[Nomor Urut 8 digit]
     * Contoh: 010.000-26.00000001
     */
    public function generateTaxInvoiceSerialNumber(
        int $tenantId,
        int $year,
        string $transactionType = '01',
        bool $isReplacement = false
    ): string {
        $year2Digits = substr((string) $year, -2);
        $replacementCode = $isReplacement ? '1' : '0';
        $prefix = "{$transactionType}{$replacementCode}.000-{$year2Digits}.";

        // Cari nomor urut terakhir pada tenant dan tahun berjalan
        $lastSerial = CustomerInvoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('tax_invoice_serial_number', 'LIKE', "{$prefix}%")
            ->orderByDesc('tax_invoice_serial_number')
            ->value('tax_invoice_serial_number');

        $sequence = 1;
        if ($lastSerial && preg_match('/\.(\d{8})$/', (string) $lastSerial, $matches)) {
            $sequence = ((int) $matches[1]) + 1;
        }

        $sequenceFormatted = str_pad((string) $sequence, 8, '0', STR_PAD_LEFT);

        return "{$prefix}{$sequenceFormatted}";
    }

    /**
     * Mengonversi entitas CustomerInvoice menjadi DTO faktur pajak standar.
     */
    public function buildDocumentFromCustomerInvoice(CustomerInvoice $invoice): TaxInvoiceDocumentDto
    {
        $invoice->loadMissing(['customer', 'lines']);

        $customer = $invoice->customer;
        $date = Carbon::parse($invoice->invoice_date);
        $year = (int) $date->format('Y');
        $month = (int) $date->format('m');

        $taxId = $customer?->tax_id;
        $cleanTaxId = $taxId ? preg_replace('/\D/', '', $taxId) : '0000000000000000';
        if (! $this->validateTaxId((string) $cleanTaxId)) {
            $cleanTaxId = '0000000000000000'; // Default NIK/NPWP Konsumen Akhir
        }

        $serialNumber = $invoice->tax_invoice_serial_number;
        if (empty($serialNumber)) {
            $serialNumber = $this->generateTaxInvoiceSerialNumber((int) $invoice->tenant_id, $year);
            $invoice->update([
                'tax_invoice_serial_number' => $serialNumber,
                'tax_invoice_status' => 'generated',
            ]);
        }

        $items = [];
        foreach ($invoice->lines as $line) {
            $unitPrice = (string) $line->unit_price;
            $qty = (string) $line->quantity;
            $total = bcmul($unitPrice, $qty, 2);
            $discount = (string) ($line->discount_amount ?? '0.00');
            $dpp = bcsub($total, $discount, 2);
            $ppn = (string) ($line->tax_amount ?? bcmul($dpp, '0.11', 2));

            $items[] = new TaxInvoiceItemDto(
                code: (string) ($line->product_id ? 'PRD-'.$line->product_id : 'SRV-01'),
                name: (string) ($line->description ?: 'Barang / Jasa Kena Pajak'),
                unitPrice: $unitPrice,
                quantity: $qty,
                totalPrice: $total,
                discountAmount: $discount,
                dpp: $dpp,
                ppn: $ppn,
                ppnbmTariff: '0.00',
                ppnbm: '0.00'
            );
        }

        // Jika tidak ada lines, gunakan subtotal dan tax_amount invoice
        if (empty($items)) {
            $headerDiscount = (string) ($invoice->discount_amount ?? '0.00');
            $headerDpp = bcsub((string) $invoice->subtotal, $headerDiscount, 2);
            $items[] = new TaxInvoiceItemDto(
                code: 'GEN-01',
                name: 'Penjualan Barang / Jasa',
                unitPrice: (string) $invoice->subtotal,
                quantity: '1',
                totalPrice: (string) $invoice->subtotal,
                discountAmount: $headerDiscount,
                dpp: $headerDpp,
                ppn: (string) $invoice->tax_amount,
                ppnbmTariff: '0.00',
                ppnbm: '0.00'
            );
        }

        // Calculate DPP and PPN totals from items for exact header-line parity
        $calcDppTotal = '0.00';
        $calcPpnTotal = '0.00';
        foreach ($items as $item) {
            $calcDppTotal = bcadd($calcDppTotal, $item->dpp, 2);
            $calcPpnTotal = bcadd($calcPpnTotal, $item->ppn, 2);
        }
        $dppAmount = $calcDppTotal;
        $ppnAmount = $calcPpnTotal;

        return new TaxInvoiceDocumentDto(
            tenantId: (int) $invoice->tenant_id,
            invoiceId: (int) $invoice->id,
            invoiceNumber: (string) $invoice->invoice_number,
            taxInvoiceSerialNumber: $serialNumber,
            invoiceDate: $date->format('d/m/Y'),
            taxPeriodMonth: $month,
            taxPeriodYear: $year,
            transactionType: '01',
            isReplacement: false,
            counterpartyName: (string) ($customer?->name ?: 'Konsumen Akhir'),
            counterpartyTaxId: $cleanTaxId,
            counterpartyAddress: (string) ($customer?->company_name ?: 'Indonesia'),
            dppAmount: $dppAmount,
            ppnAmount: $ppnAmount,
            ppnbmAmount: '0.00',
            reference: (string) $invoice->invoice_number,
            items: $items
        );
    }

    /**
     * Menghasilkan teks CSV e-Faktur resmi DJP siap impor (memuat baris FK, LT, dan OF).
     */
    public function exportCsv(
        int $tenantId,
        int $taxPeriodMonth,
        int $taxPeriodYear,
        string $direction = 'outbound'
    ): string {
        $invoices = CustomerInvoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereYear('invoice_date', $taxPeriodYear)
            ->whereMonth('invoice_date', $taxPeriodMonth)
            ->whereNotIn('status', ['void', 'cancelled'])
            ->with(['customer', 'lines'])
            ->get();

        $rows = [];
        // Header Format DJP e-Faktur
        $rows[] = 'FK;KD_JENIS_TRANSAKSI;FG_PENGGANTI;NOMOR_FAKTUR;MASA_PAJAK;TAHUN_PAJAK;TANGGAL_FAKTUR;NPWP;NAMA;ALAMAT_LENGKAP;JUMLAH_DPP;JUMLAH_PPN;JUMLAH_PPNBM;ID_KETERANGAN_TAMBAHAN;FG_UANG_MUKA;UANG_MUKA_DPP;UANG_MUKA_PPN;UANG_MUKA_PPNBM;REFERENSI';
        $rows[] = 'LT;NPWP;NAMA;JALAN;BLOK;NOMOR;RT;RW;KECAMATAN;KELURAHAN;KABUPATEN;PROPINSI;KODE_POS;NOMOR_TELEPON';
        $rows[] = 'OF;KODE_OBJEK;NAMA;HARGA_SATUAN;JUMLAH_BARANG;HARGA_TOTAL;DISKON;DPP;PPN;TARIF_PPNBM;PPNBM';

        foreach ($invoices as $invoice) {
            $doc = $this->buildDocumentFromCustomerInvoice($invoice);

            // Baris FK (Faktur Pajak Keluaran)
            $rows[] = sprintf(
                'FK;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s',
                $doc->transactionType,
                $doc->isReplacement ? '1' : '0',
                str_replace(['.', '-'], '', (string) $doc->taxInvoiceSerialNumber),
                $doc->taxPeriodMonth,
                $doc->taxPeriodYear,
                $doc->invoiceDate,
                $doc->counterpartyTaxId,
                $this->escapeCsv($doc->counterpartyName),
                $this->escapeCsv($doc->counterpartyAddress ?: 'Indonesia'),
                (int) round((float) $doc->dppAmount),
                (int) round((float) $doc->ppnAmount),
                0,
                '',
                0,
                0,
                0,
                0,
                $this->escapeCsv($doc->reference)
            );

            // Baris OF (Objek Faktur)
            foreach ($doc->items as $item) {
                $rows[] = sprintf(
                    'OF;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s',
                    $item->code,
                    $this->escapeCsv($item->name),
                    (int) round((float) $item->unitPrice),
                    (float) $item->quantity,
                    (int) round((float) $item->totalPrice),
                    (int) round((float) $item->discountAmount),
                    (int) round((float) $item->dpp),
                    (int) round((float) $item->ppn),
                    0,
                    0
                );
            }
        }

        return implode("\r\n", $rows)."\r\n";
    }

    /**
     * Menghasilkan dokumen XML e-Faktur resmi DJP.
     *
     * @param array<string, string>|null $sellerInfo
     */
    public function generateXml(TaxInvoiceDocumentDto $document, ?array $sellerInfo = null): string
    {
        return $this->xmlGenerator->generateXml($document, $sellerInfo);
    }

    /**
     * Menghasilkan dokumen JSON CoreTax REST API resmi DJP.
     *
     * @param array<string, string>|null $sellerInfo
     * @return array<string, mixed>
     */
    public function generateJson(TaxInvoiceDocumentDto $document, ?array $sellerInfo = null): array
    {
        return $this->jsonGenerator->generatePayload($document, $sellerInfo);
    }

    /**
     * Mengekspor seluruh Faktur Pajak Keluaran dalam suatu masa pajak ke format XML batch.
     */
    public function exportXml(
        int $tenantId,
        int $taxPeriodMonth,
        int $taxPeriodYear,
        ?array $sellerInfo = null
    ): string {
        $invoices = CustomerInvoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereYear('invoice_date', $taxPeriodYear)
            ->whereMonth('invoice_date', $taxPeriodMonth)
            ->whereNotIn('status', ['void', 'cancelled'])
            ->with(['customer', 'lines'])
            ->get();

        $documents = [];
        foreach ($invoices as $invoice) {
            $documents[] = $this->buildDocumentFromCustomerInvoice($invoice);
        }

        return $this->xmlGenerator->generateBatchXml($documents, $sellerInfo);
    }

    /**
     * Mengekspor seluruh Faktur Pajak Keluaran dalam suatu masa pajak ke payload JSON batch.
     *
     * @return list<array<string, mixed>>
     */
    public function exportJson(
        int $tenantId,
        int $taxPeriodMonth,
        int $taxPeriodYear,
        ?array $sellerInfo = null
    ): array {
        $invoices = CustomerInvoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereYear('invoice_date', $taxPeriodYear)
            ->whereMonth('invoice_date', $taxPeriodMonth)
            ->whereNotIn('status', ['void', 'cancelled'])
            ->with(['customer', 'lines'])
            ->get();

        $payloads = [];
        foreach ($invoices as $invoice) {
            $doc = $this->buildDocumentFromCustomerInvoice($invoice);
            $payloads[] = $this->jsonGenerator->generatePayload($doc, $sellerInfo);
        }

        return $payloads;
    }

    /**
     * Validasi dokumen dan persiapan sinkronisasi resmi CoreTax DJP API.
     *
     * @return array<string, mixed>
     */
    public function syncWithCoreTax(TaxInvoiceDocumentDto $document, ?array $sellerInfo = null): array
    {
        $this->validator->assertValid($document);
        $payload = $this->jsonGenerator->generatePayload($document, $sellerInfo);

        return [
            'status' => 'prepared',
            'validation_status' => 'validated',
            'protocol' => 'CoreTax_DJP_REST_v1',
            'tax_invoice_serial_number' => $document->taxInvoiceSerialNumber,
            'payload_hash' => $payload['payload_hash'],
            'timestamp' => now()->toIso8601String(),
            'ready_for_pjap_dispatch' => true,
            'payload' => $payload,
        ];
    }

    private function escapeCsv(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return str_replace([';', "\n", "\r"], [' ', ' ', ' '], $value);
    }
}

