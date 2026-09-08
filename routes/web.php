<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\BoothController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\FailedJobsController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\StaffShiftController;
use App\Http\Controllers\Admin\TwoFactorSetupController;
use App\Http\Controllers\Customer\ProfileController;
use App\Http\Controllers\HomeController;
use App\Http\Middleware\AdminAccess;
use App\Http\Middleware\AdminIdleTimeout;
use App\Http\Middleware\EnsureStaffTwoFactor;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::middleware(['web', 'auth', 'verified'])
    ->prefix('mypage')->name('mypage.')->group(function (): void {
        Route::get('profile', [ProfileController::class, 'show'])
            ->name('profile.show');
        Route::get('profile/edit', [ProfileController::class, 'edit'])
            ->name('profile.edit');
        Route::put('profile', [ProfileController::class, 'update'])
            ->name('profile.update');
    });

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
    Route::get('customers', [CustomerController::class, 'index'])
        ->name('customers.index');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])
        ->name('customers.show');
    Route::get('customers/{customer}/edit', [CustomerController::class, 'edit'])
        ->name('customers.edit');
    Route::put('customers/{customer}', [CustomerController::class, 'update'])
        ->name('customers.update');
    Route::get('staff', [StaffController::class, 'index'])
        ->middleware('can:staff.manage')
        ->name('staff.index');
    Route::get('staff/create', [StaffController::class, 'create'])
        ->middleware(['can:staff.manage', 'password.confirm'])
        ->name('staff.create');
    Route::post('staff', [StaffController::class, 'store'])
        ->middleware(['can:staff.manage', 'password.confirm'])
        ->name('staff.store');
    Route::get('staff/{staff}/edit', [StaffController::class, 'edit'])
        ->middleware(['can:staff.manage', 'password.confirm'])
        ->name('staff.edit');
    Route::put('staff/{staff}', [StaffController::class, 'update'])
        ->middleware(['can:staff.manage', 'password.confirm'])
        ->name('staff.update');
    Route::patch('staff/{staff}/deactivate', [StaffController::class, 'deactivate'])
        ->middleware(['can:staff.manage', 'password.confirm'])
        ->name('staff.deactivate');
    Route::middleware('can:shifts.manage')->group(function (): void {
        Route::get('staff-shifts', [StaffShiftController::class, 'index'])
            ->name('staff-shifts.index');
        Route::post('staff-shifts', [StaffShiftController::class, 'store'])
            ->name('staff-shifts.store');
        Route::put('staff-shifts/{staffShift}', [StaffShiftController::class, 'update'])
            ->name('staff-shifts.update');
        Route::delete('staff-shifts/{staffShift}', [StaffShiftController::class, 'destroy'])
            ->name('staff-shifts.destroy');
    });
    Route::middleware('can:services.manage')->group(function (): void {
        Route::resource('services', ServiceController::class)
            ->only(['index', 'create', 'store', 'edit', 'update']);
        Route::patch('services/{service}/active', [ServiceController::class, 'setActive'])
            ->name('services.set-active');
    });
    Route::middleware('can:booths.manage')->group(function (): void {
        Route::resource('booths', BoothController::class)
            ->only(['index', 'create', 'store', 'edit', 'update']);
        Route::patch('booths/{booth}/active', [BoothController::class, 'setActive'])
            ->name('booths.set-active');
    });
    Route::get('system/failed-jobs', FailedJobsController::class)
        ->middleware('can:failed_jobs.view')
        ->name('system.failed-jobs');
});
