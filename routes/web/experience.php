<?php

use App\Modules\Tenancy\Domain\Group;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// R-B reservation experience screens. Static /reservations/* paths: routes/web.php constrains /reservations/{id} to digits.
Route::middleware(['auth'])->group(function () {
    Route::get('/reservations/arrivals', fn () => Inertia::render('Reservations/Arrivals'))->middleware('can:experience.view')->name('experience.arrivals');
    Route::get('/reservations/group-booking', fn () => Inertia::render('Reservations/GroupBooking'))->middleware('can:experience.manage')->name('experience.group');
    Route::get('/reservations/rooming-import', fn () => Inertia::render('Reservations/RoomingImport'))->middleware('can:experience.manage')->name('experience.rooming');
    Route::get('/reservations/channel-health', fn () => Inertia::render('Reservations/ChannelHealth'))->middleware('can:experience.view')->name('experience.channels');
});

// Public review page (link sent after the stay): confirmation number + last name, stores a rating and comment.
Route::get('book/{group}/review', function (string $group) {
    $g = Group::where('slug', $group)->where('status', 'active')->firstOrFail();

    return Inertia::render('Public/Review', [
        'group' => ['slug' => $g->slug, 'name' => $g->name],
        'lang' => in_array(request('lang'), ['fr', 'en'], true) ? request('lang') : 'fr',
        'query' => request()->query(),
    ]);
})->middleware('throttle:public-read')->name('public.review');
