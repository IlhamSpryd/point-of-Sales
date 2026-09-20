<?php

namespace App\Http\Controllers;

class DiscountController extends Controller
{
    public function index()
    {
        return view('discounts.index');
    }
}
