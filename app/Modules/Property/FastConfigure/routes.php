<?php

use App\Modules\Property\FastConfigure\Http\FastConfigureController as F;
use Illuminate\Support\Facades\Route;

Route::prefix('property/fast-configure')->group(function () {
    Route::get('presets', [F::class, 'presets']);
    Route::get('presets/{key}', [F::class, 'preset']);
    Route::get('export', [F::class, 'export']);
    Route::post('preview', [F::class, 'preview']);
    Route::post('apply', [F::class, 'apply']);
    Route::post('demo', [F::class, 'demo']);
});
