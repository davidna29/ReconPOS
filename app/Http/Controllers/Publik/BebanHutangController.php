<?php

namespace App\Http\Controllers\Publik;

use App\Http\Controllers\BebanHutangController as AdminBebanHutangController;
use Illuminate\Http\Request;

/**
 * Mode Publik (tanpa login) — Rekap Beban Hutang read-only.
 * Memakai rekap bersama controller admin, view yang sama dgn flag $publik = true.
 * Tanpa tetapkan/template (aksi mutasi tidak didaftarkan di route publik).
 */
class BebanHutangController extends Controller
{
    public function index(Request $request)
    {
        [$cards, $grand, $belumDibebani, $daftarCabang] = AdminBebanHutangController::rekap($request);

        return view('beban-hutang.index', compact('cards', 'grand', 'belumDibebani', 'daftarCabang') + ['publik' => true]);
    }
}
