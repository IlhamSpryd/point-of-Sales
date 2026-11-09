<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\DTOs\TaxInvoiceDocumentDto;
use DOMDocument;
use DOMElement;

class CoreTaxXmlGenerator
{
    public function __construct(
        protected CoreTaxSchemaValidator $validator
    ) {}

    /**
     * Menghasilkan string XML Faktur Pajak Keluaran resmi siap impor atau transmisi ke DJP.
     *
     * @param array{
     *     npwp?: string,
     *     name?: string,
     *     address?: string,
     * }|null $sellerInfo
     */
    public function generateXml(TaxInvoiceDocumentDto $document, ?array $sellerInfo = null): string
    {
        $this->validator->assertValid($document);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS('http://djp.faktur.pajak.go.id/v1', 'resmi:fakturPajak');
        $dom->appendChild($root);

        // Header Dokumen
        $header = $dom->createElement('header');
        $root->appendChild($header);

        $header->appendChild($dom->createElement('kdJenisTransaksi', $document->transactionType));
        $header->appendChild($dom->createElement('fgPengganti', $document->isReplacement ? '1' : '0'));
        $header->appendChild($dom->createElement('nomorFaktur', (string) $document->taxInvoiceSerialNumber));
        $header->appendChild($dom->createElement('tanggalFaktur', $document->invoiceDate));
        $header->appendChild($dom->createElement('masaPajak', str_pad((string) $document->taxPeriodMonth, 2, '0', STR_PAD_LEFT)));
        $header->appendChild($dom->createElement('tahunPajak', (string) $document->taxPeriodYear));
        $header->appendChild($dom->createElement('referensi', htmlspecialchars($document->reference, ENT_XML1, 'UTF-8')));

        // Penjual (Seller)
        $penjual = $dom->createElement('penjual');
        $root->appendChild($penjual);

        $sellerNpwp = $sellerInfo['npwp'] ?? '010000000000000';
        $sellerName = $sellerInfo['name'] ?? 'PT Apeiron Global Nusantara';
        $sellerAddress = $sellerInfo['address'] ?? 'Jakarta, Indonesia';

        $penjual->appendChild($dom->createElement('npwpPenjual', preg_replace('/\D/', '', $sellerNpwp)));
        $penjual->appendChild($dom->createElement('namaPenjual', htmlspecialchars($sellerName, ENT_XML1, 'UTF-8')));
        $penjual->appendChild($dom->createElement('alamatPenjual', htmlspecialchars($sellerAddress, ENT_XML1, 'UTF-8')));

        // Pembeli / Lawan Transaksi (Buyer)
        $pembeli = $dom->createElement('pembeli');
        $root->appendChild($pembeli);

        $buyerTaxId = $document->counterpartyTaxId ? preg_replace('/\D/', '', $document->counterpartyTaxId) : '0000000000000000';
        $pembeli->appendChild($dom->createElement('npwpPembeli', $buyerTaxId));
        $pembeli->appendChild($dom->createElement('namaPembeli', htmlspecialchars($document->counterpartyName, ENT_XML1, 'UTF-8')));
        $pembeli->appendChild($dom->createElement('alamatPembeli', htmlspecialchars($document->counterpartyAddress ?: 'Indonesia', ENT_XML1, 'UTF-8')));

        // Detail Transaksi (Items)
        $detailNode = $dom->createElement('detailTransaksi');
        $root->appendChild($detailNode);

        foreach ($document->items as $item) {
            $transaksi = $dom->createElement('transaksi');
            $detailNode->appendChild($transaksi);

            $transaksi->appendChild($dom->createElement('kodeObjek', htmlspecialchars($item->code, ENT_XML1, 'UTF-8')));
            $transaksi->appendChild($dom->createElement('nama', htmlspecialchars($item->name, ENT_XML1, 'UTF-8')));
            $transaksi->appendChild($dom->createElement('hargaSatuan', number_format((float) $item->unitPrice, 2, '.', '')));
            $transaksi->appendChild($dom->createElement('jumlahBarang', number_format((float) $item->quantity, 4, '.', '')));
            $transaksi->appendChild($dom->createElement('hargaTotal', number_format((float) $item->totalPrice, 2, '.', '')));
            $transaksi->appendChild($dom->createElement('diskon', number_format((float) $item->discountAmount, 2, '.', '')));
            $transaksi->appendChild($dom->createElement('dpp', number_format((float) $item->dpp, 2, '.', '')));
            $transaksi->appendChild($dom->createElement('ppn', number_format((float) $item->ppn, 2, '.', '')));
            $transaksi->appendChild($dom->createElement('tarifPpnbm', number_format((float) $item->ppnbmTariff, 2, '.', '')));
            $transaksi->appendChild($dom->createElement('ppnbm', number_format((float) $item->ppnbm, 2, '.', '')));
        }

        // Ringkasan Pajak (Tax Summary)
        $pajak = $dom->createElement('pajak');
        $root->appendChild($pajak);

        $pajak->appendChild($dom->createElement('jumlahDpp', number_format((float) $document->dppAmount, 2, '.', '')));
        $pajak->appendChild($dom->createElement('jumlahPpn', number_format((float) $document->ppnAmount, 2, '.', '')));
        $pajak->appendChild($dom->createElement('jumlahPpnbm', number_format((float) $document->ppnbmAmount, 2, '.', '')));

        return (string) $dom->saveXML();
    }

    /**
     * Menghasilkan batch XML memuat kumpulan faktur pajak keluaran.
     *
     * @param list<TaxInvoiceDocumentDto> $documents
     * @param array{
     *     npwp?: string,
     *     name?: string,
     *     address?: string,
     * }|null $sellerInfo
     */
    public function generateBatchXml(array $documents, ?array $sellerInfo = null): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS('http://djp.faktur.pajak.go.id/v1', 'resmi:batchFakturPajak');
        $dom->appendChild($root);

        foreach ($documents as $doc) {
            $singleXml = $this->generateXml($doc, $sellerInfo);
            $singleDoc = new DOMDocument();
            $singleDoc->loadXML($singleXml);
            if ($singleDoc->documentElement) {
                $imported = $dom->importNode($singleDoc->documentElement, true);
                $root->appendChild($imported);
            }
        }

        return (string) $dom->saveXML();
    }
}
