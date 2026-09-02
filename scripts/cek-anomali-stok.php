<?php
// =====================================================================
// CEK ANOMALI STOK (READ-ONLY) — jalankan:  php scripts/cek-anomali-stok.php
// Dari folder proyek. TIDAK mengubah data apa pun; hanya mencetak laporan.
// Berguna setelah menemukan stok minus: cari tahu penyebabnya (reversal
// invoice, drift kolom stock vs kartu stok, SKU ganda per invoice, dll).
// =====================================================================

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Barang;
use App\Models\InvoicePembelian;
use App\Models\InvoicePembelianItem;
use App\Models\KartuStok;

$masukJenis = ['masuk_pembelian', 'stok_awal', 'opname'];
$keluarJenis = ['keluar_penjualan'];

echo "========== 1) BARANG DENGAN STOK MINUS ==========\n";
$negatif = Barang::where('stock', '<', 0)->orderBy('stock')->get();
if ($negatif->isEmpty()) {
    echo "Tidak ada barang dengan stok < 0.\n\n";
} else {
    foreach ($negatif as $b) {
        $rows = KartuStok::where('barang_id', $b->id)->orderBy('tanggal')->get();
        $masuk = $rows->whereIn('jenis', $masukJenis)->sum('qty');
        $keluar = $rows->whereIn('jenis', $keluarJenis)->sum('qty'); // negatif
        $totalKartu = (int) $rows->sum('qty');
        $drift = (int) $b->stock - $totalKartu; // 0 = kolom stock sinkron dgn kartu

        echo "SKU  : {$b->kode_barang} ({$b->nama})\n";
        echo "  stock (kolom)      : {$b->stock}\n";
        echo "  kartu masuk total  : {$masuk}\n";
        echo "  kartu keluar total : {$keluar}\n";
        echo "  saldo dari kartu   : {$totalKartu}\n";
        echo "  DRIFT (kolom-kartu): {$drift}   " . ($drift === 0 ? "(sinkron)" : "(TIDAK sinkron!)") . "\n";

        // invoice yang memuat SKU ini
        $itemRows = InvoicePembelianItem::with('invoicePembelian')
            ->where('barang_id', $b->id)
            ->orderBy('id')
            ->get();
        echo "  invoice terkait: ";
        if ($itemRows->isEmpty()) {
            echo "(tidak ada — mungkin semua invoice sudah dihapus, sisa reversal bekerja di kolom stock)\n";
        } else {
            echo "\n";
            foreach ($itemRows->groupBy('invoice_pembelian_id') as $invId => $rows2) {
                $inv = $rows2->first()->invoicePembelian;
                $qty = (int) $rows2->sum('qty');
                $nbaris = $rows2->count();
                echo "    - {$inv->no_invoice} ({$inv->tanggal_transaksi->format('d/m/Y')}, {$inv->status}) qty total = {$qty} ";
                echo $nbaris > 1 ? "(** SKU ganda: {$nbaris} baris **)\n" : "(1 baris)\n";
            }
        }
        echo "  10 kartu stok terakhir: ";
        foreach (array_slice($rows->all(), -10) as $r) {
            echo "[" . $r->tanggal->format('d/m H:i') . " {$r->jenis} qty={$r->qty}] ";
        }
        echo "\n\n";
    }
}

echo "========== 2) INVOICE DENGAN SKU GANDA (kandidat reversal besar) ==========\n";
$ganda = InvoicePembelianItem::selectRaw('invoice_pembelian_id, barang_id, COUNT(*) n, SUM(qty) q')
    ->groupBy('invoice_pembelian_id', 'barang_id')
    ->havingRaw('COUNT(*) > 1')
    ->orderBy('invoice_pembelian_id')
    ->get();
if ($ganda->isEmpty()) {
    echo "Tidak ada invoice dengan SKU ganda.\n";
} else {
    foreach ($ganda as $g) {
        $inv = InvoicePembelian::find($g->invoice_pembelian_id);
        $barang = Barang::find($g->barang_id);
        $namaInv = $inv ? $inv->no_invoice : '(invoice sudah dihapus)';
        $kode = $barang ? $barang->kode_barang : '(barang sudah dihapus)';
        echo "- {$namaInv} : {$kode} x {$g->n} baris (qty total {$g->q}) — hapus invoice ini mengurangi stok {$g->q}\n";
    }
}

echo "\nSelesai. Koreksi stok (bila perlu) dibahas terpisah — tool ini tidak mengubah data.\n";
