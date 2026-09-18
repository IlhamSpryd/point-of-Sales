<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class KdsController extends Controller
{
    public function index(): View
    {
        return view('kds.index');
    }
}
