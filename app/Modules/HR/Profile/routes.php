<?php

use App\Modules\HR\Profile\Http\ProfileController as PC;
use Illuminate\Support\Facades\Route;

// H-C employee 360 profile. Included from app/Modules/HR/routes.php. Sections are permission-filtered in the service.
Route::prefix('hr/employees/{employee}/profile')->whereNumber('employee')->controller(PC::class)->group(function () {
    Route::get('/', 'show');
    Route::get('{section}', 'section')->where('section', '[a-z]+');
});
