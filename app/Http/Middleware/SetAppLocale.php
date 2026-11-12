<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SetAppLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = session('locale');

        // Barista portal defaults to Khmer when the user hasn't picked a language yet
        if (!$locale && Auth::check() && Auth::user()?->role === 'barista') {
            $locale = 'km';
        }

        if (!in_array($locale, ['en', 'km'], true)) {
            $locale = 'en';
        }

        App::setLocale($locale);

        return $next($request);
    }
}

