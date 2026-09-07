<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\FailedJobsController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\TwoFactorSetupController;
use App\Http\Controllers\HomeController;
use App\Http\Middleware\AdminAccess;
use App\Http\Middleware\AdminIdleTimeout;
use App\Http\Middleware\EnsureStaffTwoFactor;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::middleware([
    'web',
    'auth',
    'verified',
    AdminAccess::class,
    AdminIdleTimeout::class,
    EnsureStaffTwoFactor::class,
])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/', AdminDashboardController::class)->name('dashboard');
    Route::get('two-factor-setup', [TwoFactorSetupController::class, 'show'])
        ->name('two-factor-setup');
    Route::get('staff', [StaffController::class, 'index'])
        ->middleware('can:staff.manage')
        ->name('staff.index');
    Route::get('staff/create', [StaffController::class, 'create'])
        ->middleware(['can:staff.manage', 'password.confirm'])
        ->name('staff.create');
    Route::post('staff', [StaffController::class, 'store'])
        ->middleware(['can:staff.manage', 'password.confirm'])
        ->name('staff.store');
    Route::get('system/failed-jobs', FailedJobsController::class)
        ->middleware('can:failed_jobs.view')
        ->name('system.failed-jobs');
});
