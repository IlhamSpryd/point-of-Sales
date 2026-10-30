<?php

declare(strict_types=1);

use App\Livewire\HR\AttendanceMonitor;
use App\Livewire\HR\CommissionManager;
use App\Livewire\HR\EmployeeManager;
use App\Livewire\HR\LeaveManager;
use App\Livewire\HR\PayrollBatchManager;
use App\Livewire\HR\WorkforceScheduler;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Human Resources, Workforce & Payroll Routes (Modul 9)
|--------------------------------------------------------------------------
|
| Terkoneksi dengan arsitektur multi-tenant, RBAC peran Owner & Manager.
|
*/

Route::middleware(['auth', 'tenant.context', 'enforce.email.verification', 'ensure.onboarding.completed'])->prefix('hr')->name('hr.')->group(function () {
    Route::middleware('role:Owner,Manager')->group(function () {
        Route::get('/', fn () => redirect()->route('hr.employees.index'))->name('index');
        Route::get('/employees', EmployeeManager::class)->name('employees.index');
        Route::get('/schedules', WorkforceScheduler::class)->name('schedules.index');
        Route::get('/attendance', AttendanceMonitor::class)->name('attendance.index');
        Route::get('/leaves', LeaveManager::class)->name('leaves.index');
        Route::get('/commissions', CommissionManager::class)->name('commissions.index');
        Route::get('/payroll', PayrollBatchManager::class)->name('payroll.index');
    });
});
