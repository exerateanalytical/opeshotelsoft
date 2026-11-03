<?php

namespace App\Modules\POS\Http;

use Illuminate\Http\Request;

/** Outlet set-up (tables, price levels) is a manager task: view with pos.view/manage, change with pos.configure. */
trait ConfiguresPos
{
    protected function authorize(Request $request, string $ability): void
    {
        $u = $request->user();
        $ok = $ability === 'view'
            ? ($u->can('pos.view') || $u->can('pos.manage') || $u->can('pos.configure'))
            : $u->can('pos.configure');
        abort_unless($ok, 403, 'You do not have permission for this action.');
    }
}
