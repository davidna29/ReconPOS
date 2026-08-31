<?php

namespace App\Enums;

enum JenisMutasiStok: string
{
    case MASUK_PEMBELIAN = 'masuk_pembelian';
    case KELUAR_PENJUALAN = 'keluar_penjualan';
    case STOK_AWAL = 'stok_awal';
    case OPNAME = 'opname';
}
