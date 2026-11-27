<?php

declare(strict_types=1);

namespace App\Modules\Integration\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Controllers\MidtransNotificationController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentNotificationController extends Controller
{
    public function __construct(
        protected MidtransNotificationController $canonicalController
    ) {}

    public function handle(Request $request): JsonResponse
    {
        return $this->canonicalController->handle($request);
    }
}
