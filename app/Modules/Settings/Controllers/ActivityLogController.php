<?php

namespace App\Modules\Settings\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Contracts\View\View;

class ActivityLogController extends Controller
{
    public function index(): View
    {
        $logs = ActivityLog::with('user')
            ->latest()
            ->paginate(15);

        return view('activity-logs.index', compact('logs'));
    }
}
