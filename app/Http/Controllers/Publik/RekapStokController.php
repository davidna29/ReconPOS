<?php

namespace App\Http\Controllers\Publik;

use App\Http\Controllers\RekapStokController as AdminRekapStokController;
use Illuminate\Http\Request;

/**
 * Mode Publik (tanpa login) — Rekap Stok per Merk read-only.
 * Memakai query+grouping bersama controller admin, view yang sama dgn flag $publik = true.
 */
class RekapStokController extends Controller
{
    public function index(Request $request)
    {
        [$groups, $totalSku, $totalStok, $merks] = AdminRekapStokController::rekap($request);

        return view('rekap-stok.index', compact('groups', 'totalSku', 'totalStok', 'merks') + ['publik' => true]);
    }
}
