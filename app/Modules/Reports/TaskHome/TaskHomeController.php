<?php

namespace App\Modules\Reports\TaskHome;

use Illuminate\Http\Request;

/** GET /api/v1/tasks/home: any signed-in staff; each section is filtered by the user's own permissions. */
class TaskHomeController
{
    public function __invoke(Request $request, TaskHomeService $home)
    {
        return $home->forUser($request->user());
    }
}
