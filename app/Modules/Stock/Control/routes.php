<?php

use App\Modules\Stock\Control\Http\ControlController as C;
use Illuminate\Support\Facades\Route;

// I-A inventory control, included from app/Modules/Stock/routes.php.
Route::prefix('stock-control')->group(function () {
    Route::get('recipes', [C::class, 'recipes']);
    Route::post('recipes', [C::class, 'storeRecipe']);
    Route::get('recipes/{recipe}', [C::class, 'showRecipe']);
    Route::put('recipes/{recipe}', [C::class, 'updateRecipe']);
    Route::post('recipes/{recipe}/recost', [C::class, 'recost']);
    Route::get('recipes/{recipe}/history', [C::class, 'recipeHistory']);

    Route::get('units/{item}', [C::class, 'units']);
    Route::post('units/{item}', [C::class, 'defineUnit']);
    Route::get('units/{item}/convert', [C::class, 'convert']);

    Route::get('variance', [C::class, 'variance']);
    Route::get('variance/reviews', [C::class, 'reviews']);
    Route::post('variance/reviews', [C::class, 'saveReview']);
    Route::get('variance/reviews/{review}', [C::class, 'showReview']);
    Route::put('variance/reviews/{review}/lines/{line}', [C::class, 'annotate']);
    Route::post('variance/reviews/{review}/finalize', [C::class, 'finalizeReview']);

    Route::get('par-levels', [C::class, 'parLevels']);
    Route::put('par-levels', [C::class, 'savePar']);
    Route::delete('par-levels/{par}', [C::class, 'deletePar']);
    Route::get('lead-times', [C::class, 'leadTimes']);
    Route::put('lead-times/{supplier}', [C::class, 'setLeadTime']);
    Route::get('reorder/suggestions', [C::class, 'suggestions']);
    Route::post('reorder/convert', [C::class, 'convertSuggestions']);

    Route::get('lots', [C::class, 'lots']);
    Route::post('lots', [C::class, 'receiveLot']);
    Route::get('lots/expiry', [C::class, 'expiry']);
    Route::get('lots/fefo', [C::class, 'fefo']);
    Route::put('lots/{lot}', [C::class, 'updateLot']);
    Route::post('lots/{lot}/write-off', [C::class, 'writeOff']);
    Route::get('item-controls/{item}', [C::class, 'itemControl']);
    Route::put('item-controls/{item}', [C::class, 'setItemControl']);

    Route::get('valuation', [C::class, 'valuation']);
});
