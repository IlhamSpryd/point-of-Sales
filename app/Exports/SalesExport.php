<?php

declare(strict_types=1);

namespace App\Exports;

use App\Enums\OrderStatus;
use App\Models\Order;
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

    public function __construct(protected Carbon $start, protected Carbon $end) {}

    public function query(): Builder
    {
        return Order::query()
            ->select(['id', 'user_id', 'order_code', 'payment_method', 'order_amount', 'created_at'])
            ->with('user:id,name')
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
        return ['Tanggal', 'Kode Pesanan', 'Kasir', 'Metode Pembayaran', 'Total Nilai (Rp)'];
    }

    public function map($order): array
    {
        return [
            $order->created_at->format('d/m/Y H:i'),
            $order->order_code,
            $order->user ? $order->user->name : 'Kasir',
            strtoupper($order->payment_method?->value ?? '-'),
            $order->order_amount,
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

        $sheet->getStyle("E2:E{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');

        return [];
    }
}
