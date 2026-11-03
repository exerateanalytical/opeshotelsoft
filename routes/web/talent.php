<?php

use App\Modules\Tenancy\Domain\Group;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// H-B talent screens (staff), the public careers site and the employee self-service portal.
Route::middleware(['auth'])->prefix('hr/talent')->group(function () {
    Route::get('/', fn () => Inertia::render('HR/TalentRecruitment'))->middleware('can:talent.view')->name('talent.recruitment');
    Route::get('/skills', fn () => Inertia::render('HR/TalentSkills'))->middleware('can:talent.view')->name('talent.skills');
    Route::get('/training', fn () => Inertia::render('HR/TalentTraining'))->middleware('can:talent.view')->name('talent.training');
    Route::get('/planning', fn () => Inertia::render('HR/TalentPlanning'))->middleware('can:talent.view')->name('talent.planning');
    Route::get('/compliance', fn () => Inertia::render('HR/TalentCompliance'))->middleware('can:talent.view')->name('talent.compliance');
});

// Self-service: any signed-in user linked to an employee record (the API answers 403 otherwise).
Route::middleware(['auth'])->get('/me', fn () => Inertia::render('Me/Index'))->name('me');

// Public careers site per group. The offer link comes first so a token is never read as a group slug.
$careers = fn (string $component) => function (string $group, ?string $post = null) use ($component) {
    $g = Group::where('slug', $group)->where('status', 'active')->firstOrFail();

    return Inertia::render("Careers/$component", ['group' => ['slug' => $g->slug, 'name' => $g->name], 'postId' => $post ? (int) $post : null, 'lang' => in_array(request('lang'), ['fr', 'en'], true) ? request('lang') : 'fr']);
};
Route::get('/careers/offer/{token}', fn (string $token) => Inertia::render('Careers/Offer', ['token' => $token, 'lang' => in_array(request('lang'), ['fr', 'en'], true) ? request('lang') : 'fr']))
    ->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:public-read');
Route::get('/careers/{group}', $careers('Index'))->middleware('throttle:public-read')->name('careers');
Route::get('/careers/{group}/{post}', $careers('Index'))->whereNumber('post')->middleware('throttle:public-read')->name('careers.post');
