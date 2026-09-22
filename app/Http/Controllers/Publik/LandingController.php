<?php

namespace App\Http\Controllers\Publik;

use App\Models\Barang;
use App\Models\TokoSetting;
use Illuminate\Http\Request;

class LandingController extends Controller
{
    public function index(Request $request)
    {
        $settings = TokoSetting::utama();
        $totalBarang = Barang::where('tipe_item', 'barang')->count();
        $totalStok = (int) Barang::where('tipe_item', 'barang')->sum('stock');

        return view('publik.landing', compact('settings', 'totalBarang', 'totalStok') + ['publik' => true]);
    }
}
