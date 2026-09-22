<?php

namespace App\Http\Controllers\Publik;

use App\Models\TokoSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PinController extends Controller
{
    public function form(Request $request)
    {
        $settings = TokoSetting::utama();

        // Jika PIN tidak aktif, langsung ke publik
        if (! $settings->publik_pin_enabled || empty($settings->publik_pin_hash)) {
            return redirect()->route('publik.landing');
        }

        // Sudah authenticated → langsung ke tujuan
        if ($request->session()->get('publik_authenticated') === true) {
            return redirect()->intended(route('publik.landing'));
        }

        return view('publik.pin', ['publik' => true]);
    }

    public function verify(Request $request)
    {
        $settings = TokoSetting::utama();

        if (! $settings->publik_pin_enabled || empty($settings->publik_pin_hash)) {
            return redirect()->route('publik.landing');
        }

        $data = $request->validate([
            'pin' => ['required', 'string', 'min:4', 'max:20'],
        ]);

        if (! Hash::check($data['pin'], $settings->publik_pin_hash)) {
            return back()->with('error', 'PIN salah. Coba lagi.')->withInput();
        }

        $request->session()->put('publik_authenticated', true);
        $request->session()->regenerate();

        return redirect()->intended(route('publik.landing'))->with('success', 'Akses Mode Publik dibuka.');
    }

    public function clear(Request $request)
    {
        $request->session()->forget('publik_authenticated');

        return redirect()->route('publik.pin.form')->with('success', 'Keluar dari Mode Publik. Masukkan PIN lagi untuk akses.');
    }
}
