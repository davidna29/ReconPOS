<?php

namespace App\Http\Middleware;

use App\Models\TokoSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublikPinMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $settings = TokoSetting::utama();

        if (! $settings->publik_pin_enabled || empty($settings->publik_pin_hash)) {
            return $next($request);
        }

        // Admin yang sedang login boleh bypass PIN (klik "Lihat Publik" tetap lolos)
        if (auth()->check()) {
            return $next($request);
        }

        if ($request->session()->get('publik_authenticated') === true) {
            return $next($request);
        }

        // Jangan redirect loop saat sudah di halaman PIN
        if ($request->routeIs('publik.pin.*')) {
            return $next($request);
        }

        // Simpan tujuan agar setelah PIN benar langsung ke halaman yang diminta
        if (! $request->isMethod('post')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('publik.pin.form');
    }
}
