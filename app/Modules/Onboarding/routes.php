<?php

use App\Modules\Onboarding\Http\OnboardingController as O;
use Illuminate\Support\Facades\Route;

Route::prefix('onboarding')->group(function () {
    Route::get('state', [O::class, 'state']);
    Route::put('state', [O::class, 'save']);
    Route::put('group', [O::class, 'group']);
    Route::post('go-live', [O::class, 'goLive']);
    Route::get('imports', [O::class, 'imports']);
    Route::get('imports/{kind}/template', [O::class, 'importTemplate'])->where('kind', '[a-z_]+');
    Route::post('imports/{kind}', [O::class, 'import'])->where('kind', '[a-z_]+');
    Route::post('copy', [O::class, 'copy']);
});
