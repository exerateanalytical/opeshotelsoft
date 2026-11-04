<?php

namespace App\Modules\Reports\Dashboard;

use Illuminate\Http\Request;

/** GET /api/v1/reports/dashboard/compose: the widgets and order for the signed-in user's role. */
class DashboardComposeController
{
    public function __invoke(Request $request, DashboardComposer $composer)
    {
        return $composer->compose($request->user());
    }
}
