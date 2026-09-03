<?php
// =====================================================================
// BERSIHKAN STOK YATIM (kartu masuk_pembelian tanpa invoice terhubung)
//   php scripts/bersihkan-stok-yatim.php            -> DRY-RUN (default)
//   php scripts/bersihkan-stok-yatim.php --apply    -> EKSEKUSI
//
// Baris karth "masuk_pembelian" dgn invoice_pembelian_id = NULL (stok
// yatim/orphan) tidak bisa di-reverse oleh hapus invoice. Script ini:
//   - DRY-RUN: mencetak SKU terdampak & stok sebelum/sesudah.
//   - APPLY  : set qty = 0 pada baris yatim (baris TETAP ada utk audit,
//              keterangan ditambah "(stok yatim dibersihkan)"), lalu
//              set barang.stock = SUM(kartu tersisa).
// =====================================================================

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Barang;
use App\Models\KartuStok;
use Illuminate\Support\Facades\DB;

$apply = in_array('--apply', $argv, true);
if ($apply) {
    echo "MODE: APPLY (menulis DB)\n\n";
} else {
    echo "MODE: DRY-RUN — tambahkan --apply utk benar-benar mengeksekusi.\n\n";
}

$affected = 0;
$totalYatim = 0;

foreach (Barang::where('tipe_item', 'barang')->get() as $b) {
    $orphan = KartuStok::where('barang_id', $b->id)
        ->where('jenis', 'masuk_pembelian')
        ->whereNull('invoice_pembelian_id')
        ->get();

    if ($orphan->isEmpty()) {
        continue;
    }

    $yatim = (int) $orphan->sum('qty');
    $stokSebelum = (int) $b->stock;
    $stokSesudah = $stokSebelum - $yatim;

    $affected++;
    $totalYatim += $yatim;
    echo "SKU {$b->kode_barang} ({$b->nama})\n";
    echo "  baris yatim : " . $orphan->count() . " baris, qty {$yatim}\n";
    echo "  stock       : {$stokSebelum} -> {$stokSesudah}\n";

    if ($apply) {
        DB::transaction(function () use ($orphan, $b, $yatim, $stokSesudah) {
            foreach ($orphan as $row) {
                $ket = trim((string) $row->keterangan);
                $row->update([
                    'qty' => 0,
                    'keterangan' => $ket . ($ket !== '' ? ' — ' : '') . '(stok yatim dibersihkan)',
                ]);
            }
            $b->update(['stock' => $stokSesudah]);
        });
        echo "  -> OK diterapkan\n";
    }
}

echo "\nRingkasan: " . $affected . " SKU terdampak, total stok yatim " . $totalYatim . " unit.\n";
if (! $apply) {
    echo "Jalankan:  php scripts/bersihkan-stok-yatim.php --apply\n";
}
