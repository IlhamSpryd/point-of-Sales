<?php

namespace App\Jobs;

use App\Models\ChannelOrderLog;
use App\Services\TransactionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessWebhookOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public ChannelOrderLog $log) {}

    public function handle(TransactionService $transactionService): void
    {
        if ($this->log->status !== 'pending') return;

        $this->log->update(['status' => 'processing']);

        try {
            DB::transaction(function () use ($transactionService) {
                // Di sini Anda akan memanggil $transactionService->createTransaction()
                // dengan mapping data dari $this->log->payload.
                
                // ┌─ SYNC ALERT — NODE 1 (BACKEND) ──────────────────────┐
                // │ Saat ini TransactionService menganggap semua status 'paid'
                // │ harus melalui metode 'cash'. Untuk order Omnichannel yang 
                // │ sudah dibayar di aplikasi (OVO/Gopay), service perlu 
                // │ dimodifikasi untuk menerima flag `is_externally_paid`.
                // └──────────────────────────────────────────────────────┘
            });

            $this->log->update(['status' => 'completed']);
        } catch (\Exception $e) {
            $this->log->update(['status' => 'failed', 'error_message' =>$e->getMessage()]);
            // JANGAN re-throw agar worker tidak retries membabi-buta, catat saja.
        }
    }
}
