<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\DTOs\TaxInvoiceDocumentDto;
use DomainException;
use InvalidArgumentException;

class CoreTaxSchemaValidator
{
    /**
     * Standard transaction types per DJP e-Faktur regulations:
     * 01: Penyerahan BKP/JKP kepada pihak selain pemungut PPN
     * 02: Penyerahan BKP/JKP kepada Pemungut Bendaharawan Pemerintah
     * 03: Penyerahan BKP/JKP kepada Pemungut BUMN/BUMD
     * 04: Penyerahan BKP/JKP yang DPP-nya menggunakan Nilai Lain
     * 05: Penyerahan BKP/JKP yang menggunakan besaran tertentu
     * 06: Penyerahan Lainnya
     * 07: Penyerahan BKP/JKP yang mendapat fasilitas PPN Tidak Dipungut
     * 08: Penyerahan BKP/JKP yang mendapat fasilitas PPN Dibebaskan
     * 09: Penyerahan Aktiva yang menurut tujuan semula tidak untuk diperjualbelikan
     */
    public const VALID_TRANSACTION_TYPES = [
        '01', '02', '03', '04', '05', '06', '07', '08', '09',
    ];

    /**
     * Memvalidasi format NPWP / NIK untuk CoreTax Indonesia.
     * Mendukung 15 digit (NPWP konvensional) atau 16 digit (NIK / NPWP 16 digit PMK 112/2022).
     */
    public function isValidTaxId(?string $taxId): bool
    {
        if ($taxId === null || trim($taxId) === '') {
            return false;
        }

        $clean = preg_replace('/\D/', '', $taxId);
        if ($clean === null) {
            return false;
        }

        return in_array(strlen($clean), [15, 16], true);
    }

    /**
     * Memvalidasi format Nomor Seri Faktur Pajak (NSFP).
     * Standar: [0-9]{3}\.[0-9]{3}-[0-9]{2}\.[0-9]{8} (misal: 010.000-26.00000001)
     */
    public function isValidTaxInvoiceSerialNumber(?string $serialNumber): bool
    {
        if ($serialNumber === null || trim($serialNumber) === '') {
            return false;
        }

        return (bool) preg_match('/^\d{3}\.\d{3}-\d{2}\.\d{8}$/', trim($serialNumber));
    }

    /**
     * Validasi menyeluruh dokumen faktur pajak sebelum diserialisasi ke XML/JSON atau dikirim ke CoreTax DJP.
     *
     * @return array<string, list<string>> Array error dikelompokkan berdasarkan field (kosong jika valid)
     */
    public function validateDocument(TaxInvoiceDocumentDto $document): array
    {
        $errors = [];

        // 1. Validasi Identitas Dokumen
        if ($document->tenantId <= 0) {
            $errors['tenant_id'][] = 'Tenant ID must be a positive integer.';
        }

        if (trim($document->invoiceNumber) === '') {
            $errors['invoice_number'][] = 'Invoice number cannot be empty.';
        }

        if ($document->taxInvoiceSerialNumber !== null && ! $this->isValidTaxInvoiceSerialNumber($document->taxInvoiceSerialNumber)) {
            $errors['tax_invoice_serial_number'][] = "Tax invoice serial number '{$document->taxInvoiceSerialNumber}' has an invalid DJP format.";
        }

        if (! in_array($document->transactionType, self::VALID_TRANSACTION_TYPES, true)) {
            $errors['transaction_type'][] = "Invalid DJP transaction type code: '{$document->transactionType}'. Expected 01 through 09.";
        }

        if ($document->taxPeriodMonth < 1 || $document->taxPeriodMonth > 12) {
            $errors['tax_period_month'][] = 'Tax period month must be between 1 and 12.';
        }

        if ($document->taxPeriodYear < 2020 || $document->taxPeriodYear > 2100) {
            $errors['tax_period_year'][] = 'Tax period year is out of reasonable range.';
        }

        // 2. Validasi Lawan Transaksi (Pembeli)
        if (trim($document->counterpartyName) === '') {
            $errors['counterparty_name'][] = 'Counterparty name cannot be empty.';
        }

        if ($document->counterpartyTaxId !== null && $document->counterpartyTaxId !== '') {
            $cleanTaxId = preg_replace('/\D/', '', $document->counterpartyTaxId);
            if (! in_array(strlen((string) $cleanTaxId), [15, 16], true)) {
                $errors['counterparty_tax_id'][] = 'Counterparty NPWP/NIK must be 15 or 16 numeric digits.';
            }
        }

        // 3. Validasi Detail Barang / Jasa (Items)
        if (empty($document->items)) {
            $errors['items'][] = 'Tax invoice document must contain at least one line item.';
        } else {
            $calcDppTotal = '0.00';
            $calcPpnTotal = '0.00';

            foreach ($document->items as $index => $item) {
                if (bccomp($item->quantity, '0', 4) <= 0) {
                    $errors["items.{$index}.quantity"][] = "Item {$item->code} quantity must be strictly greater than 0.";
                }

                if (bccomp($item->unitPrice, '0', 2) < 0) {
                    $errors["items.{$index}.unit_price"][] = "Item {$item->code} unit price cannot be negative.";
                }

                $expectedTotal = bcmul($item->unitPrice, $item->quantity, 2);
                $discountAmount = $item->discountAmount !== '' ? $item->discountAmount : '0.00';
                $expectedDpp = bcsub($expectedTotal, $discountAmount, 2);

                if (abs((float) bcsub($expectedDpp, $item->dpp, 2)) > 0.05) {
                    $errors["items.{$index}.dpp"][] = "Item {$item->code} DPP ({$item->dpp}) does not match price - discount ({$expectedDpp}).";
                }

                $calcDppTotal = bcadd($calcDppTotal, $item->dpp, 2);
                $calcPpnTotal = bcadd($calcPpnTotal, $item->ppn, 2);
            }

            // 4. Validasi Akurasi Finansial Header vs Summary Lines
            if (abs((float) bcsub($calcDppTotal, $document->dppAmount, 2)) > 0.05) {
                $errors['dpp_amount'][] = "Document DPP ({$document->dppAmount}) does not match the sum of items DPP ({$calcDppTotal}).";
            }

            if (abs((float) bcsub($calcPpnTotal, $document->ppnAmount, 2)) > 0.05) {
                $errors['ppn_amount'][] = "Document PPN ({$document->ppnAmount}) does not match the sum of items PPN ({$calcPpnTotal}).";
            }
        }

        return $errors;
    }

    /**
     * Memvalidasi dokumen dan melempar DomainException jika ditemukan ketidaksesuaian skema.
     *
     * @throws DomainException
     */
    public function assertValid(TaxInvoiceDocumentDto $document): void
    {
        $errors = $this->validateDocument($document);
        if (! empty($errors)) {
            $firstMessage = reset($errors)[0] ?? 'CoreTax schema validation failure.';
            $allMessages = [];
            foreach ($errors as $field => $fieldErrors) {
                $allMessages[] = "{$field}: ".implode(', ', $fieldErrors);
            }

            throw new DomainException(
                "CoreTax e-Faktur validation failed: {$firstMessage} Details: ".implode(' | ', $allMessages)
            );
        }
    }
}
