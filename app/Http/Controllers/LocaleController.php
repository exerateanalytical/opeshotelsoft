<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/** Language switch: saved on the user when signed in, in the session otherwise. */
class LocaleController
{
    public const SUPPORTED = ['fr', 'en'];

    public const DEFAULT = 'fr';

    public function update(Request $request)
    {
        $data = $request->validate(['locale' => ['required', 'in:'.implode(',', self::SUPPORTED)]]);
        $request->session()->put('locale', $data['locale']);
        $request->user()?->update(['locale' => $data['locale']]);

        return back();
    }
}
