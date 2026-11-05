<?php

declare(strict_types=1);

namespace App\Modules\Sales\Exceptions;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ShiftClosedException extends ValidationException
{
    public function __construct(
        string $message = 'Kasir tidak dapat bertransaksi di luar sesi shift aktif. Silakan buka shift baru sebelum melanjutkan.'
    ) {
        $validator = Validator::make([], []);
        parent::__construct($validator);
        $this->message = $message;
    }
}
