<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\WebSession;
use Illuminate\Http\Request;

class WebSessionController extends Controller
{
    public function show(Request $request, WebSession $session)
    {
        return response()->json(['data' => $session->check($request) ?? ['idle_timeout' => null]])->header('Cache-Control', 'private, no-store');
    }

    public function activity(Request $request, WebSession $session)
    {
        return response()->json(['data' => $session->check($request, true) ?? ['idle_timeout' => null]])->header('Cache-Control', 'private, no-store');
    }
}
