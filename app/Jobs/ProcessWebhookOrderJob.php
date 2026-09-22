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
        if ($this->log->status !== 'pending') {
            return;
        }

        $this->log->update(['status' => 'processing']);

        try {
            DB::transaction(function () {
                // Di sini Anda akan memanggil $transactionService->createTransaction()
                // dengan mapping data dari $this->log->payload.

                // [OMEGA-NODE2] Fix: TransactionService::createTransaction() sekarang sudah
                // mendukung parameter `payments` (Split Payment / Omnichannel) sehingga tidak
                // perlu lagi flag `is_externally_paid`. Cukup berikan:
                // 'payments' => [['method' => 'ewallet', 'amount' => $totalAmountDariPayload]]
                // Order akan otomatis menjadi Paid dan tidak memanggil Midtrans Snap.
            });

            $this->log->update(['status' => 'completed']);
        } catch (\Exception $e) {
            $this->log->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            // JANGAN re-throw agar worker tidak retries membabi-buta, catat saja.
        }
    }
}
