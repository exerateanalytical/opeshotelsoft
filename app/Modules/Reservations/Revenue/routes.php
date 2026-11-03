<?php

use App\Modules\Reservations\Revenue\Http\OffersController;
use App\Modules\Reservations\Revenue\Http\OverbookingController;
use App\Modules\Reservations\Revenue\Http\PricingController;
use App\Modules\Reservations\Revenue\Http\RulesController;
use App\Modules\Reservations\Revenue\Http\UpsellController;
use Illuminate\Support\Facades\Route;

// R-A revenue engine API (included by Reservations/routes.php, mounted under /api/v1).
Route::prefix('revenue')->group(function () {
    Route::get('rate-plans', [RulesController::class, 'plans']);
    Route::get('rate-plans/{ratePlan}', [RulesController::class, 'show']);
    Route::put('rate-plans/{ratePlan}/rules', [RulesController::class, 'saveRule']);
    Route::put('rate-plans/{ratePlan}/package', [RulesController::class, 'savePackage']);
    Route::delete('rate-plans/{ratePlan}/package', [RulesController::class, 'removePackage']);
    Route::get('quote', [RulesController::class, 'quote']);

    Route::get('settings', [PricingController::class, 'settings']);
    Route::put('settings', [PricingController::class, 'saveSettings']);
    Route::get('events', [PricingController::class, 'events']);
    Route::post('events', [PricingController::class, 'saveEvent']);
    Route::patch('events/{event}', [PricingController::class, 'saveEvent']);
    Route::delete('events/{event}', [PricingController::class, 'removeEvent']);
    Route::get('competitor-rates', [PricingController::class, 'competitors']);
    Route::post('competitor-rates', [PricingController::class, 'saveCompetitor']);
    Route::delete('competitor-rates/{competitor}', [PricingController::class, 'removeCompetitor']);
    Route::get('suggestions', [PricingController::class, 'suggestions']);
    Route::post('suggestions/generate', [PricingController::class, 'generate']);
    Route::post('suggestions/{suggestion}/accept', [PricingController::class, 'accept']);
    Route::post('suggestions/{suggestion}/reject', [PricingController::class, 'reject']);

    Route::get('overbooking', [OverbookingController::class, 'policies']);
    Route::put('overbooking/policies/{roomType}', [OverbookingController::class, 'savePolicy']);
    Route::post('overbooking/apply', [OverbookingController::class, 'apply']);
    Route::get('overbooking/exposure', [OverbookingController::class, 'exposure']);
    Route::get('walks', [OverbookingController::class, 'walks']);
    Route::post('reservations/{reservation}/walk', [OverbookingController::class, 'walk']);

    Route::get('upsell-offers', [UpsellController::class, 'index']);
    Route::post('upsell-offers/pre-arrival-run', [UpsellController::class, 'preArrivalRun']);
    Route::post('reservations/{reservation}/upsell-offers', [UpsellController::class, 'offer']);
    Route::post('upsell-offers/{offer}/accept', [UpsellController::class, 'accept']);
    Route::post('upsell-offers/{offer}/decline', [UpsellController::class, 'decline']);

    Route::get('promo-codes', [OffersController::class, 'promos']);
    Route::post('promo-codes', [OffersController::class, 'savePromo']);
    Route::patch('promo-codes/{promo}', [OffersController::class, 'savePromo']);
    Route::get('contracts', [OffersController::class, 'contracts']);
    Route::post('contracts', [OffersController::class, 'saveContract']);
    Route::patch('contracts/{contract}', [OffersController::class, 'saveContract']);
    Route::post('contracts/{contract}/allotments', [OffersController::class, 'addAllotment']);
    Route::get('contracts/{contract}/availability', [OffersController::class, 'availability']);
    Route::post('allotments/release', [OffersController::class, 'releaseAllotments']);
});
