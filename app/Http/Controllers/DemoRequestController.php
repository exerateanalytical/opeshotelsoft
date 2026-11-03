<?php

namespace App\Http\Controllers;

use App\Models\DemoRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DemoRequestController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        // Hidden field that humans leave empty: bots that fill it get a silent success.
        if ($request->filled('website')) {
            return back();
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'hotel' => ['nullable', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:100'],
            'rooms' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'phone' => ['nullable', 'string', 'max:40', 'required_without:email'],
            'email' => ['nullable', 'email', 'max:160', 'required_without:phone'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $lead = DemoRequest::create($data + ['locale' => app()->getLocale(), 'ip' => $request->ip()]);
        Log::info('Demo request received', ['id' => $lead->id, 'hotel' => $lead->hotel, 'city' => $lead->city]);

        return back()->with('demo_requested', true);
    }
}
