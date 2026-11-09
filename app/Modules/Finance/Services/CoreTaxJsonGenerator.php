<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\DTOs\TaxInvoiceDocumentDto;

class CoreTaxJsonGenerator
{
    public function __construct(
        protected CoreTaxSchemaValidator $validator
    ) {}

    /**
     * Menghasilkan struktur array JSON standar DJP CoreTax v1 REST API.
     *
     * @param array{
     *     npwp?: string,
     *     name?: string,
     *     address?: string,
     * }|null $sellerInfo
     * @return array<string, mixed>
     */
    public function generatePayload(TaxInvoiceDocumentDto $document, ?array $sellerInfo = null): array
    {
        $this->validator->assertValid($document);

        $cleanBuyerTaxId = $document->counterpartyTaxId
            ? preg_replace('/\D/', '', $document->counterpartyTaxId)
            : '0000000000000000';

        $cleanSellerTaxId = isset($sellerInfo['npwp'])
            ? preg_replace('/\D/', '', $sellerInfo['npwp'])
            : '010000000000000';

        $items = [];
        foreach ($document->items as $item) {
            $items[] = [
                'item_code' => $item->code,
                'item_name' => $item->name,
                'unit_price' => number_format((float) $item->unitPrice, 2, '.', ''),
                'quantity' => number_format((float) $item->quantity, 4, '.', ''),
                'total_price' => number_format((float) $item->totalPrice, 2, '.', ''),
                'discount_amount' => number_format((float) $item->discountAmount, 2, '.', ''),
                'dpp' => number_format((float) $item->dpp, 2, '.', ''),
                'ppn' => number_format((float) $item->ppn, 2, '.', ''),
                'ppnbm_tariff' => number_format((float) $item->ppnbmTariff, 2, '.', ''),
                'ppnbm' => number_format((float) $item->ppnbm, 2, '.', ''),
            ];
        }

        $payload = [
            'protocol' => 'CoreTax_DJP_REST_v1',
            'schema_version' => '1.0.0',
            'header' => [
                'transaction_type' => $document->transactionType,
                'is_replacement' => $document->isReplacement,
                'tax_invoice_serial_number' => $document->taxInvoiceSerialNumber,
                'invoice_date' => $document->invoiceDate,
                'tax_period_month' => $document->taxPeriodMonth,
                'tax_period_year' => $document->taxPeriodYear,
                'commercial_invoice_number' => $document->invoiceNumber,
                'reference' => $document->reference,
            ],
            'seller' => [
                'tax_id' => $cleanSellerTaxId,
                'tax_id_type' => strlen((string) $cleanSellerTaxId) === 16 ? 'NIK_16' : 'NPWP_15',
                'name' => $sellerInfo['name'] ?? 'PT Apeiron Global Nusantara',
                'address' => $sellerInfo['address'] ?? 'Jakarta, Indonesia',
            ],
            'buyer' => [
                'tax_id' => $cleanBuyerTaxId,
                'tax_id_type' => strlen((string) $cleanBuyerTaxId) === 16 ? 'NIK_16' : 'NPWP_15',
                'name' => $document->counterpartyName,
                'address' => $document->counterpartyAddress ?: 'Indonesia',
            ],
            'items' => $items,
            'tax_summary' => [
                'currency' => 'IDR',
                'dpp_total' => number_format((float) $document->dppAmount, 2, '.', ''),
                'ppn_total' => number_format((float) $document->ppnAmount, 2, '.', ''),
                'ppnbm_total' => number_format((float) $document->ppnbmAmount, 2, '.', ''),
            ],
            'generated_at' => now()->toIso8601String(),
        ];

        $payloadHash = hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR));
        $payload['payload_hash'] = $payloadHash;

        return $payload;
    }

    /**
     * Menghasilkan string JSON resmi siap transmisi ke CoreTax endpoint.
     *
     * @param array{
     *     npwp?: string,
     *     name?: string,
     *     address?: string,
     * }|null $sellerInfo
     */
    public function generateJson(TaxInvoiceDocumentDto $document, ?array $sellerInfo = null): string
    {
        $payload = $this->generatePayload($document, $sellerInfo);

        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
