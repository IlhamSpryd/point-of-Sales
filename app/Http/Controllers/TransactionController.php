<?php

declare(strict_types=1);

namespace App\Http\Controllers;

/**
 * Backward compatibility wrapper for modular architecture.
 *
 * @see \App\Modules\Sales\Controllers\TransactionController
 */
class TransactionController extends \App\Modules\Sales\Controllers\TransactionController {}
