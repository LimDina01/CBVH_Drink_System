<?php

use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Language Toggle (EN <-> KM)
Route::get('/lang/{locale}', function (string $locale) {
    if (in_array($locale, ['en', 'km'], true)) {
        session(['locale' => $locale]);
    }
    return redirect()->back(fallback: '/');
})->name('lang.switch');

// Staff Authentication
Route::get('/login', function () {
    if (Auth::check()) {
        $role = Auth::user()->role;
        return redirect($role === 'admin' ? '/admin' : ($role === 'barista' ? '/barista' : '/'));
    }
    return view('pages.login');
})->name('login');

Route::post('/logout', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();
    return redirect()->route('login');
})->name('logout');

// Protected Application Routes
Route::middleware('auth')->group(function () {
    // 1. Telegram Mini App / Staff Drink Ordering Portal
    Route::get('/', function () {
        return view('pages.mini-app');
    })->name('mini-app');

    // 2. Barista & Kitchen Display (Barista or Admin only)
    Route::get('/barista', function () {
        return view('pages.barista');
    })->middleware('role:barista,admin')->name('barista.display');

    // 3. Admin Reports & Staff Portal (Admin only)
    Route::get('/admin', function () {
        return view('pages.admin');
    })->middleware('role:admin')->name('admin.approvals');
});

// 4. Telegram Bot Webhook (Public, CSRF-exempt)
Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle'])->name('telegram.webhook');
