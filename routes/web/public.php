<?php

use App\Modules\Tenancy\Domain\Group;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
| Public guest booking website (W02). No login: data comes from the public
| Booking API (/api/v1/booking/{slug}/...), authorised per booking by the
| manage token returned at lookup. Language via ?lang=fr|en.
*/
$page = fn (string $component) => function (string $group) use ($component) {
    $g = Group::where('slug', $group)->where('status', 'active')->firstOrFail();

    return Inertia::render("Public/$component", [
        'group' => ['slug' => $g->slug, 'name' => $g->name],
        'lang' => in_array(request('lang'), ['fr', 'en'], true) ? request('lang') : 'fr',
        'query' => request()->query(),
    ]);
};

Route::prefix('book/{group}')->middleware('throttle:public-read')->name('public.')->group(function () use ($page) {
    Route::get('/', $page('Hotels'))->name('hotels');
    Route::get('/search', $page('Search'))->name('search');
    Route::get('/details', $page('Details'))->name('details');
    Route::get('/confirmation', $page('Confirmation'))->name('confirmation');
    Route::get('/manage', $page('Manage'))->name('manage');
});
