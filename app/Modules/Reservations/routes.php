<?php

use App\Modules\Reservations\Http\AriController;
use App\Modules\Reservations\Http\BlockController;
use App\Modules\Reservations\Http\RatePlanController;
use App\Modules\Reservations\Http\ReservationController;
use App\Modules\Reservations\Http\SeasonController;
use App\Modules\Reservations\Http\TapeChartController;
use App\Modules\Reservations\Http\TravelAgentController;
use App\Modules\Reservations\Http\WaitlistController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/Revenue/routes.php'; // R-A revenue engine
require __DIR__.'/Experience/routes.php'; // R-B reservation experience

Route::apiResource('rate-plans', RatePlanController::class);
Route::get('rates', [AriController::class, 'rates']);
Route::put('rates', [AriController::class, 'setRates']);
Route::get('inventory', [AriController::class, 'inventory']);
Route::put('inventory', [AriController::class, 'setInventory']);
Route::get('availability', [AriController::class, 'search']);

Route::get('rate-plans/{ratePlan}/seasons', [SeasonController::class, 'index']);
Route::post('rate-plans/{ratePlan}/seasons', [SeasonController::class, 'store']);
Route::patch('rate-plans/{ratePlan}/seasons/{season}', [SeasonController::class, 'update']);
Route::delete('rate-plans/{ratePlan}/seasons/{season}', [SeasonController::class, 'destroy']);

// Static paths before reservations/{reservation}.
Route::get('reservations/tape-chart', TapeChartController::class);

Route::get('waitlist', [WaitlistController::class, 'index']);
Route::post('waitlist', [WaitlistController::class, 'store']);
Route::post('waitlist/{entry}/offer', [WaitlistController::class, 'offer']);
Route::post('waitlist/{entry}/convert', [WaitlistController::class, 'convert']);
Route::post('waitlist/{entry}/cancel', [WaitlistController::class, 'cancel']);

Route::get('travel-agents', [TravelAgentController::class, 'index']);
Route::post('travel-agents', [TravelAgentController::class, 'store']);
Route::get('travel-agents/commissions', [TravelAgentController::class, 'commissions']);
Route::patch('travel-agents/{agent}', [TravelAgentController::class, 'update']);

Route::get('reservations', [ReservationController::class, 'index']);
Route::post('reservations', [ReservationController::class, 'store']);
Route::get('reservations/{reservation}', [ReservationController::class, 'show']);
Route::patch('reservations/{reservation}', [ReservationController::class, 'update']);
Route::post('reservations/{reservation}/cancel', [ReservationController::class, 'cancel']);
Route::get('reservations/{reservation}/cancellation-quote', [ReservationController::class, 'cancellationQuote']);
Route::post('reservations/{reservation}/no-show', [ReservationController::class, 'noShow']);
Route::post('reservations/{reservation}/assign-room', [ReservationController::class, 'assignRoom']);
Route::get('reservations/{reservation}/available-rooms', [ReservationController::class, 'availableRooms']);

Route::get('blocks', [BlockController::class, 'index']);
Route::post('blocks', [BlockController::class, 'store']);
Route::get('blocks/{block}', [BlockController::class, 'show']);
Route::post('blocks/{block}/rooming-list', [BlockController::class, 'roomingList']);
Route::post('blocks/{block}/release', [BlockController::class, 'release']);
