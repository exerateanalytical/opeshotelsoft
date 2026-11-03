<?php

use App\Modules\Reports\Http\ReportsController as R;
use Illuminate\Support\Facades\Route;

Route::prefix('reports')->group(function () {
    Route::get('dashboard', [R::class, 'dashboard']);
    Route::get('group-dashboard', [R::class, 'group']);
    Route::get('forecast', [R::class, 'forecast']);
    Route::get('channels', [R::class, 'channels']);
});
