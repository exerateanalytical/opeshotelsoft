<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\DemoLoginController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', fn () => auth('member')->check() && ! request()->query('tab') ? redirect('/account') : Inertia::render('Auth/Login', ['demoAccounts' => DemoLoginController::accounts(), 'memberDemoAccounts' => \App\Http\Controllers\Auth\DemoMemberLoginController::accounts(), 'tab' => request()->query('tab') === 'member' ? 'member' : 'staff']))->name('login');
    Route::post('/demo-login/{login}', [DemoLoginController::class, 'store'])->middleware('throttle:20,1')->name('demo-login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/forgot-password', fn () => Inertia::render('Auth/ForgotPassword'))->name('password.request');
    Route::get('/two-factor-challenge', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [TwoFactorController::class, 'verify'])->middleware('throttle:10,1')->name('two-factor.verify');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

// Language switch (guests and staff).
Route::post('/locale', [LocaleController::class, 'update'])->name('locale');

// Landing page for visitors; signed-in staff go straight to the dashboard.
Route::get('/', fn () => auth('web')->check() ? redirect()->route('dashboard') : Inertia::render('Landing/Index'))->name('home');

/*
|--------------------------------------------------------------------------
| Authenticated Application Routes
|--------------------------------------------------------------------------
| Every staff page carries a can: guard matching its sidebar entry (App\Http\Navigation).
*/
Route::middleware(['auth'])->group(function () {

    // Dashboard (all staff; widgets hide what the role cannot see)
    Route::get('/dashboard', fn () => Inertia::render('Dashboard/Index'))->name('dashboard');

    // Reservations
    Route::prefix('reservations')->name('reservations.')->group(function () {
        Route::get('/', fn () => Inertia::render('Reservations/Index'))->middleware('can:reservations.view')->name('index');
        Route::get('/create', fn () => Inertia::render('Reservations/Create'))->middleware('can:reservations.manage')->name('create');
        Route::get('/{id}', fn ($id) => Inertia::render('Reservations/Show', compact('id')))->whereNumber('id')->middleware('can:reservations.view')->name('show');
    });

    // Front Desk
    Route::prefix('front-desk')->name('front-desk.')->group(function () {
        Route::get('/', fn () => Inertia::render('FrontDesk/Index'))->middleware('can:frontdesk.view')->name('index');
    });

    // Housekeeping
    Route::prefix('housekeeping')->name('housekeeping.')->group(function () {
        Route::get('/', fn () => Inertia::render('Housekeeping/Index'))->middleware('can:housekeeping.view')->name('index');
    });

    // Loyalty
    Route::prefix('loyalty')->name('loyalty.')->middleware('can:loyalty.view')->group(function () {
        Route::get('/', fn () => Inertia::render('Loyalty/Index'))->name('index');
        Route::get('/members', fn () => Inertia::render('Loyalty/Members'))->name('members');
    });

    // Finance
    Route::prefix('finance')->name('finance.')->group(function () {
        Route::get('/', fn () => Inertia::render('Finance/Index'))->middleware('can:accounting.view')->name('index');
    });

    // Two closures on one line serialise wrongly under `route:cache` (the page returned the Route object).
    Route::prefix('stock')->name('stock.')->group(function () {
        Route::get('/', fn () => Inertia::render('Stock/Index'))->middleware('can:stock.view')->name('index');
    });

    // POS
    Route::prefix('pos')->name('pos.')->group(function () {
        Route::get('/', fn () => Inertia::render('POS/Index'))->middleware('can:pos.view')->name('index');
    });

    // Reports
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/', fn () => Inertia::render('Reports/Index'))->middleware('can:reports.view')->name('index');
    });

    // Properties
    Route::prefix('properties')->name('properties.')->group(function () {
        Route::get('/', fn () => Inertia::render('Property/Index'))->middleware('can:property.view')->name('index');
    });

    // Guests
    Route::prefix('guests')->name('guests.')->group(function () {
        Route::get('/', fn () => Inertia::render('Guests/Index'))->middleware('can:guests.view')->name('index');
    });

    // HR
    Route::prefix('hr')->name('hr.')->group(function () {
        Route::get('/', fn () => Inertia::render('HR/Index'))->middleware('can:hr.view')->name('index');
    });

    // Maintenance
    Route::prefix('maintenance')->name('maintenance.')->group(function () {
        Route::get('/', fn () => Inertia::render('Maintenance/Index'))->middleware('can:maintenance.view')->name('index');
    });

    // Billing
    Route::prefix('billing')->name('billing.')->group(function () {
        Route::get('/invoices', fn () => Inertia::render('Billing/Invoices'))->middleware('can:folios.view')->name('invoices');
        Route::get('/invoices/{id}', fn ($id) => Inertia::render('Billing/InvoiceShow', compact('id')))->whereNumber('id')->middleware('can:folios.view')->name('invoices.show');
        Route::get('/cashier', fn () => Inertia::render('Billing/Cashier'))->middleware('can:cashier.view')->name('cashier');
        Route::get('/folios', fn () => Inertia::render('Billing/Folios'))->middleware('can:folios.view')->name('folios');
    });

    // Group bookings
    Route::prefix('groups')->name('groups.')->middleware('can:reservations.view')->group(function () {
        Route::get('/', fn () => Inertia::render('Groups/Index'))->name('index');
        Route::get('/{id}', fn ($id) => Inertia::render('Groups/Show', compact('id')))->whereNumber('id')->name('show');
    });

    // Channel manager
    Route::get('/channels', fn () => Inertia::render('Channels/Index'))->middleware('can:channels.view')->name('channels.index');

    // Settings
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/users', fn () => Inertia::render('Settings/Users'))->middleware('can:users.view')->name('users');
        Route::get('/hotels', fn () => Inertia::render('Settings/Hotels'))->middleware('can:hotels.view')->name('hotels');
    });

    // Administration (app/Modules/Admin)
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/audit', fn () => Inertia::render('Admin/Audit'))->middleware('can:audit.view')->name('audit');
        Route::get('/currencies', fn () => Inertia::render('Admin/Currencies'))->middleware('can:currencies.view')->name('currencies');
    });

    // Developer
    Route::get('/developer', fn () => Inertia::render('Developer/Index'))->middleware('can:integrations.view')->name('developer');

    // Profile (all staff), including two-factor enrolment
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
    Route::post('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/password', [ProfileController::class, 'password'])->name('profile.password');
    Route::post('/profile/two-factor', [TwoFactorController::class, 'enable'])->name('profile.two-factor.enable');
    Route::post('/profile/two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:10,1')->name('profile.two-factor.confirm');
    Route::post('/profile/two-factor/recovery-codes', [TwoFactorController::class, 'regenerate'])->name('profile.two-factor.recovery');
    Route::delete('/profile/two-factor', [TwoFactorController::class, 'disable'])->name('profile.two-factor.disable');
});

// Feature route files (one per workstream) declare their own middleware groups.
foreach (glob(__DIR__.'/web/*.php') as $routes) {
    require $routes;
}

// Unknown URLs: a NotFound page for signed-in staff, the login screen for guests.
Route::fallback(fn () => Inertia::render('Errors/NotFound')->toResponse(request())->setStatusCode(404))->middleware('auth');
