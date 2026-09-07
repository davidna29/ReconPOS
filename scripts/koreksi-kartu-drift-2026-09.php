<?php
// =====================================================================
// KOREKSI DRIFT KARTU STOK (temuan audit 07/09/2026, satu-kali pakai)
//   php scripts/koreksi-kartu-drift-2026-09.php            -> DRY-RUN (default)
//   php scripts/koreksi-kartu-drift-2026-09.php --apply    -> EKSEKUSI
//
// Konteks: 2 SKU drift — kolom barang.stock SESUAI fisik, tapi kartu stok
// kelebihan karena unit keluar fisik tanpa nota tercatat (tidak ada baris
// keluar_penjualan):
//   - BL-20570R15-CHA-ECO (BAN LUAR 205/70 R15 CHAMPIRO ECOTEC):
//       stock=2 vs kartu=15 → selisih -13 (masuk 5+10, keluar 0)
//   - PCR-18570R14-CHA-ECO (PCR GT 185/70 R14 CHAMPIRO ECOTEC):
//       stock=0 vs kartu=14 → selisih -14 (opname +28 lalu -14, lalu 14
//       unit keluar fisik tanpa catatan)
//
// Script ini menambah 1 baris kartu per SKU (jenis keluar_penjualan,
// invoice & penjualan NULL, keterangan audit) TANPA mengubah kolom stock.
// Sengaja TIDAK lewat StockService::mutasi() karena mutasi selalu ikut
// mengubah kolom stock (yang di sini sudah benar).
// Jenis keluar_penjualan dipilih agar laporan Stok Tracking (FIFO, yg hanya
// mengonsumsi via keluar_penjualan) ikut terkoreksi; baris opname negatif
// hanya jadi batch bersisa negatif yg disembunyikan sehingga aging tetap
// menampilkan stok hantu.
//
// Guard: abort bila drift saat eksekusi ≠ ekspektasi, atau koreksi sudah ada.
// =====================================================================

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Barang;
use App\Models\KartuStok;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Target koreksi: kode => qty kartu yg ditambahkan (negatif = keluar).
$target = [
    'BL-20570R15-CHA-ECO' => -13,
    'PCR-18570R14-CHA-ECO' => -14,
];
$penanda = 'Koreksi audit 07/09/2026';

$apply = in_array('--apply', $argv, true);
if ($apply) {
    echo "MODE: APPLY (menulis DB)\n\n";
} else {
    echo "MODE: DRY-RUN — tambahkan --apply utk benar-benar mengeksekusi.\n\n";
}

$siap = 0;
foreach ($target as $kode => $qtyKoreksi) {
    $b = Barang::where('kode_barang', $kode)->first();
    if (! $b) {
        echo "SKU {$kode}: TIDAK DITEMUKAN — lewati.\n";
        continue;
    }

    $sudahAda = KartuStok::where('barang_id', $b->id)
        ->where('keterangan', 'like', '%' . $penanda . '%')
        ->exists();
    if ($sudahAda) {
        echo "SKU {$kode}: koreksi sudah pernah diterapkan — lewati (idempoten).\n";
        continue;
    }

    $sumKartu = (int) KartuStok::where('barang_id', $b->id)->sum('qty');
    $drift = (int) $b->stock - $sumKartu; // ekspektasi: sama dgn $qtyKoreksi
    echo "SKU {$kode} ({$b->nama})\n";
    echo "  stock kolom : {$b->stock} | kartu sum : {$sumKartu} | drift : {$drift} (ekspektasi {$qtyKoreksi})\n";

    if ($drift !== $qtyKoreksi) {
        echo "  -> ABORT: drift berubah sejak audit — JANGAN paksa. Investigasi ulang dulu.\n";
        continue;
    }

    $ket = $penanda . ': ' . abs($qtyKoreksi) . ' unit keluar fisik tanpa nota tercatat'
        . ' (stok fisik sesuai kolom, tgl keluar aktual tdk diketahui)'
        . ' — sinkronisasi kartu saja, kolom stock tidak diubah.';
    echo "  akan tambah: [keluar_penjualan qty={$qtyKoreksi}] {$ket}\n";

    if ($apply) {
        DB::transaction(function () use ($b, $qtyKoreksi, $ket) {
            KartuStok::create([
                'barang_id' => $b->id,
                'tanggal' => Carbon::now(),
                'jenis' => 'keluar_penjualan',
                'qty' => $qtyKoreksi,
                'invoice_pembelian_id' => null,
                'transaksi_penjualan_id' => null,
                'keterangan' => $ket,
            ]);
            // Kolom stock SENGAJA tidak diubah (sudah sesuai fisik).
        });
        echo "  -> OK diterapkan\n";
    }
    $siap++;
}

echo "\nRingkasan: {$siap} dari " . count($target) . " SKU siap dikoreksi.\n";
if (! $apply) {
    echo "Cek output di atas, lalu jalankan:  php scripts/koreksi-kartu-drift-2026-09.php --apply\n";
    echo "Sesudahnya verifikasi:  php scripts/cek-anomali-stok.php   (section 3 harus kosong)\n";
}
