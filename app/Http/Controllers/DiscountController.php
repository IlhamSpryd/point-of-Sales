<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
