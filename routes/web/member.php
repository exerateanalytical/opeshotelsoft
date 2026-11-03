<?php

use App\Http\Controllers\Auth\DemoMemberLoginController;
use App\Modules\MemberPortal\Http\MemberAccountController as A;
use App\Modules\MemberPortal\Http\MemberAuthController as Auth;
use App\Modules\MemberPortal\Http\Middleware\MemberAuth;
use Illuminate\Support\Facades\Route;

// Customer portal (loyalty-club members). Guard `member`; staff routes and guards are untouched.
Route::prefix('account')->group(function () {
    // Public steps: throttled, identical answers whether or not the contact exists.
    Route::middleware('throttle:20,1')->group(function () {
        Route::post('register', [Auth::class, 'register']);
        Route::post('register/verify', [Auth::class, 'verifyRegistration']);
        Route::post('login', [Auth::class, 'login']);
        Route::post('login/code', [Auth::class, 'sendCode']);
        Route::post('login/code/verify', [Auth::class, 'verifyCode']);
        Route::post('forgot', [Auth::class, 'forgot']);
        Route::post('reset', [Auth::class, 'reset']);
    });

    Route::middleware(MemberAuth::class)->group(function () {
        Route::post('logout', [Auth::class, 'logout']);
        Route::get('/', [A::class, 'home'])->name('account.home');
        Route::get('card', [A::class, 'card']);
        Route::post('card/join', [A::class, 'join']);
        Route::post('card/{card}/block', [A::class, 'blockCard'])->whereNumber('card');
        Route::post('card/{card}/replace', [A::class, 'replaceCard'])->whereNumber('card');
        Route::get('card/{card}/pdf', [A::class, 'cardPdf'])->whereNumber('card');
        Route::get('points', [A::class, 'points']);
        Route::get('points/pdf', [A::class, 'pointsPdf']);
        Route::get('bookings', [A::class, 'bookings']);
        Route::get('bookings/{number}', [A::class, 'bookingDetail']);
        Route::post('bookings/{number}/modify', [A::class, 'modify']);
        Route::post('bookings/{number}/cancel', [A::class, 'cancel']);
        Route::post('bookings/{number}/pay', [A::class, 'pay']);
        Route::get('invoices/{id}/pdf', [A::class, 'invoicePdf'])->whereNumber('id');
        Route::get('receipts/{id}/pdf', [A::class, 'receiptPdf'])->whereNumber('id');
        Route::get('book', [A::class, 'bookPage']);
        Route::post('book/search', [A::class, 'search'])->middleware('throttle:30,1');
        Route::post('book', [A::class, 'book'])->middleware('throttle:10,1');
        Route::get('hotels', [A::class, 'hotels']);
        Route::get('chat', [A::class, 'chatPage']);
        Route::get('chat/messages', [A::class, 'chatMessages'])->middleware('throttle:60,1');
        Route::post('chat', [A::class, 'chatSend'])->middleware('throttle:30,1');
        Route::get('requests', [A::class, 'requestsPage']);
        Route::post('requests', [A::class, 'requestCreate'])->middleware('throttle:20,1');
        Route::get('profile', [A::class, 'profilePage']);
        Route::post('profile', [A::class, 'profileUpdate']);
        Route::post('profile/password', [A::class, 'password'])->middleware('throttle:10,1');
    });
});

Route::post('/demo-member-login/{n}', [DemoMemberLoginController::class, 'store'])->whereNumber('n')->middleware('throttle:20,1')->name('demo-member-login');
