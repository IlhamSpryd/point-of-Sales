<?php

declare(strict_types=1);

namespace App\Modules\Customers\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class DiscountController extends Controller
{
    /**
     * Display the discount and promotional management page.
     * Renders the Livewire discount-manager component within the app layout.
     */
    public function index(): View
    {
        return view('discounts.index');
    }
}
