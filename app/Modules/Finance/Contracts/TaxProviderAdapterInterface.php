<?php

declare(strict_types=1);

namespace App\Modules\Finance\Contracts;

use App\Modules\Finance\DTOs\TaxInvoiceDocumentDto;
use App\Modules\Finance\DTOs\TaxSubmissionResultDto;

interface TaxProviderAdapterInterface
{
    /**
     * Nama unik penyedia / adaptor pajak.
     */
    public function getProviderName(): string;

    /**
     * Submit dokumen faktur pajak ke penyedia pajak atau workflow internal.
     *
     * @param array<string, mixed> $options
     */
    public function submitInvoice(TaxInvoiceDocumentDto $document, array $options = []): TaxSubmissionResultDto;

    /**
     * Cek status pemrosesan faktur pajak dari penyedia.
     */
    public function getSubmissionStatus(string $submissionReference): TaxSubmissionResultDto;

    /**
     * Batalkan faktur pajak yang telah diterbitkan beserta alasannya.
     */
    public function cancelInvoice(string $taxInvoiceSerialNumber, string $reason): TaxSubmissionResultDto;
}
