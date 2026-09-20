<?php

namespace App\Http\Controllers;

class ActivityLogController extends Controller
{
    public function index()
    {
        return view('activity-logs.index');
    }
}
