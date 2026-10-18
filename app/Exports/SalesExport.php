<?php

declare(strict_types=1);

namespace App\Exports;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * CRITICAL FIX (M6-B-001): FromCollection -> FromQuery + WithChunkReading.
 * The previous version called ->get() and hydrated every matching Order
 * (plus its `user` relation) into memory at once. On a full-year export
 * for a busy outlet (tens of thousands of paid orders) that risks a fatal
 * "Allowed memory size exhausted" error PHP cannot reliably route through
 * a catch(Throwable) block, leaving the ExportTask stuck at 'processing'
 * forever. FromQuery + WithChunkReading makes Maatwebsite Excel pull and
 * write rows in small batches instead of one giant array.
 */
class SalesExport implements FromQuery, ShouldAutoSize, WithChunkReading, WithHeadings, WithMapping, WithStyles
{
    private const CHUNK_SIZE = 1000;

    public function __construct(
        protected Carbon $start,
        protected Carbon $end,
        protected bool $allStores = false
    ) {}

    public function query(): Builder
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $storeId = app(TenantContext::class)->getStoreId();

        $baseQuery = $this->allStores ? Order::allStores() : Order::query();

        $baseQuery
            ->select(['id', 'user_id', 'order_code', 'payment_method', 'subtotal_amount', 'discount_amount', 'tax_amount', 'service_charge_amount', 'order_amount', 'created_at', 'tenant_id', 'store_id'])
            ->with([
                'user' => function ($q) use ($tenantId) {
                    $q->select(['id', 'name', 'tenant_id'])->where('tenant_id', $tenantId);
                },
                'paymentRefunds' => function ($q) {
                    $q->where('request_status', 'completed');
                },
            ])
            ->where('tenant_id', $tenantId);

        if (! $this->allStores && $storeId !== null) {
            $baseQuery->where('store_id', $storeId);
        }

        return $baseQuery
            ->whereBetween('order_date', [$this->start, $this->end])
            ->where('order_status', OrderStatus::Paid->value)
            ->orderBy('created_at', 'desc');
    }

    public function chunkSize(): int
    {
        return self::CHUNK_SIZE;
    }

    public function headings(): array
    {
        return [
            'Tanggal',
            'Kode Pesanan',
            'Kasir',
            'Metode Pembayaran',
            'Gross Sales (Rp)',
            'Diskon (Rp)',
            'Pajak (Rp)',
            'Service Charge (Rp)',
            'Total Tagihan (Rp)',
            'Retur & Refund (Rp)',
            'Net Sales (Rp)',
        ];
    }

    public function map($order): array
    {
        $refundAmount = (float) $order->paymentRefunds->sum('amount');
        $netSales = max(0.00, (float) $order->order_amount - $refundAmount);

        return [
            $order->created_at->format('d/m/Y H:i'),
            $order->order_code,
            $order->user ? $order->user->name : 'Kasir',
            strtoupper($order->payment_method?->value ?? '-'),
            (float) $order->subtotal_amount,
            (float) $order->discount_amount,
            (float) $order->tax_amount,
            (float) $order->service_charge_amount,
            (float) $order->order_amount,
            $refundAmount,
            $netSales,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = $sheet->getHighestRow();
        $lastCol = $sheet->getHighestColumn();

        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF09090B']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->getStyle("A1:{$lastCol}{$lastRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE4E4E7']]],
        ]);

        $sheet->getStyle("E2:K{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');

        return [];
    }
}
