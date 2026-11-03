<?php

use App\Modules\Channels\Http\ChannelController as C;
use Illuminate\Support\Facades\Route;

Route::prefix('channels')->group(function () {
    Route::get('providers', [C::class, 'providers']);
    Route::get('connections', [C::class, 'index']);
    Route::post('connections', [C::class, 'store']);
    Route::patch('connections/{connection}', [C::class, 'update']);
    Route::put('connections/{connection}/mappings', [C::class, 'mappings']);
    Route::get('connections/{connection}/preview', [C::class, 'preview']);
    Route::post('connections/{connection}/sync', [C::class, 'sync']);
    Route::post('connections/{connection}/test', [C::class, 'test']);
    Route::get('connections/{connection}/logs', [C::class, 'logs']);
});
