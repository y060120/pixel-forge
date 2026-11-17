<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// Public Pixelforge webhook endpoint (CSRF excluded in bootstrap/app.php)
Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');

// Landing page redirect
Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('generations.index')
        : redirect()->route('login');
});

Route::middleware(['auth'])->group(function () {
    // Redirect dashboard to generations studio
    Route::get('/dashboard', fn () => redirect()->route('generations.index'))->name('dashboard');

    // Image Generations Studio & History
    Route::get('/generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('/generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::get('/generations/estimate', [GenerationController::class, 'estimate'])->name('generations.estimate');
    Route::get('/generations/poll', [GenerationController::class, 'poll'])->name('generations.poll');

    // Credits / Balances
    Route::post('/credits/buy', [CreditController::class, 'buy'])->name('credits.buy');

    // Admin Operator Report
    Route::get('/admin/report', [AdminReportController::class, 'index'])->name('admin.report');

    // User Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
