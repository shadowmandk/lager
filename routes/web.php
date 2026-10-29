<?php

use App\Http\Middleware\EnsureOperator;
use App\Http\Middleware\EnsureUnlocked;
use App\Livewire\ActivityLog;
use App\Livewire\Admin;
use App\Livewire\AdminLogin;
use App\Livewire\Board;
use App\Livewire\OperatorPicker;
use App\Livewire\Statistics;
use App\Livewire\UnlockScreen;
use App\Support\BoardVersion;
use App\Support\DeviceLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/laas', UnlockScreen::class)->name('unlock');

Route::post('/laas', function (Request $request) {
    DeviceLock::lock();

    return $request->expectsJson() ? response()->noContent() : redirect()->route('unlock');
})->name('lock');

Route::middleware(EnsureUnlocked::class)->group(function () {
    Route::get('/', OperatorPicker::class)->name('operator.pick');

    Route::middleware(EnsureOperator::class)->group(function () {
        Route::get('/tavle', Board::class)->name('board');
        Route::get('/log', ActivityLog::class)->name('log');
        Route::get('/statistik', Statistics::class)->name('statistics');
    });

    Route::get('/ping', fn () => response()->json(['version' => BoardVersion::current()]))->name('ping');
});

Route::get('/admin/login', AdminLogin::class)->name('login')->middleware('guest');

Route::middleware('auth')->group(function () {
    Route::get('/admin', Admin::class)->name('admin');
    Route::get('/admin/backup/{file}', [App\Http\Controllers\BackupController::class, 'download'])
        ->where('file', '[A-Za-z0-9_.-]+')
        ->name('admin.backup');
    Route::post('/admin/logout', function (Request $request) {
        // Kun admin-login nulstilles; valgt medarbejder på enheden bevares.
        Auth::logout();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
});
