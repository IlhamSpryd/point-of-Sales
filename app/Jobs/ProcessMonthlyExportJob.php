<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessMonthlyExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Batasi retries agar tidak menumpuk file rusak
    public $tries = 1;

    public function __construct(public string $month, public string $year) {}

    public function handle(): void
    {
        $filename = "exports/sales_{$this->month}_{$this->year}_".time().'.csv';
        $handle = fopen(storage_path('app/'.$filename), 'w');

        fputcsv($handle, ['Order Code', 'Total', 'Payment Method', 'Status', 'Date']);

        // Menggunakan chunkById agar memori flat walau ada 1 juta data
        Order::whereMonth('created_at', $this->month)
            ->whereYear('created_at', $this->year)
            ->chunkById(1000, function ($orders) use ($handle) {
                foreach ($orders as $order) {
                    fputcsv($handle, [$order->order_code,
                        $order->order_amount, $order->payment_method?->value ?? '-',
                        $order->order_status?->value ?? '-',
                        $order->created_at->format('Y-m-d H:i:s'),
                    ]);
                }
            });

        fclose($handle);

        // ┌─ SYNC ALERT — NODE 2 (UI) ───────────────────────────┐
        // │ Beri tahu UI bahwa export selesai (misal via WebSocket
        // │ atau update status di tabel `export_tasks` untuk didownload).
        // └──────────────────────────────────────────────────────┘
    }
}
