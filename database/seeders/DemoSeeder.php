<?php

namespace Database\Seeders;

use App\Models\Barang;
use App\Models\KategoriBarang;
use App\Models\Supplier;
use App\Models\TokoSetting;
use App\Services\GenerateService;
use App\Services\HargaService;
use App\Services\PembelianService;
use App\Services\PenjualanService;
use Illuminate\Database\Seeder;

/**
 * Data demo realistis bengkel: kategori, barang (sebagian ber-SN), supplier,
 * pembelian tersebar di bulan berjalan (sumber stok masuk), SN, dan 2 penjualan manual.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $kategori = collect([
            'Oli & Pelumas', 'Filter', 'Rem', 'Aki & Kelistrikan', 'Mesin', 'Ban', 'Lampu',
        ])->mapWithKeys(fn ($n) => [$n => KategoriBarang::firstOrCreate(['nama' => $n])->id]);

        $suppliers = collect([
            ['nama' => 'PT. Bintang Sparepart', 'kontak' => '0812-1111-2222', 'email' => 'sales@bintangsp.id', 'alamat' => 'Jl. Industri Raya 12', 'catatan' => 'Termin 30 hari'],
            ['nama' => 'Toko Oli Makmur', 'kontak' => '0813-3333-4444', 'alamat' => 'Pasar Sentral Blok B', 'catatan' => 'Supplier oli & pelumas'],
            ['nama' => 'CV. Maju Jaya Ban', 'kontak' => '0815-5555-6666', 'alamat' => 'Jl. Gatot Subroto 88'],
        ])->map(fn ($s) => Supplier::firstOrCreate(['kontak' => $s['kontak']], $s)->id);

        // [kode, nama, kategori, satuan, SN?, harga_beli, margin_override?, mode, stok_awal]
        $barangDef = [
            ['OLI-001', 'Oli Mesin 0.8L', 'Oli & Pelumas', 'pcs', 'NON_SN', 45000, null, 'otomatis', 12],
            ['OLI-002', 'Oli Gear 120ml', 'Oli & Pelumas', 'pcs', 'NON_SN', 18000, null, 'otomatis', 20],
            ['FLT-001', 'Filter Udara', 'Filter', 'pcs', 'NON_SN', 35000, null, 'otomatis', 15],
            ['FLT-002', 'Filter Oli', 'Filter', 'pcs', 'NON_SN', 25000, null, 'otomatis', 15],
            ['KPR-001', 'Kampas Rem Depan', 'Rem', 'pcs', 'NON_SN', 60000, 25, 'otomatis', 8],
            ['KPR-002', 'Kampas Rem Belakang', 'Rem', 'pcs', 'NON_SN', 55000, null, 'otomatis', 8],
            ['AKI-001', 'Aki GS Arena 35Ah', 'Aki & Kelistrikan', 'pcs', 'SN', 350000, null, 'otomatis', 4],
            ['AKI-002', 'Aki Yuasa 45Ah', 'Aki & Kelistrikan', 'pcs', 'SN', 480000, 15, 'otomatis', 3],
            ['BUS-001', 'Busi Iridium', 'Aki & Kelistrikan', 'pcs', 'NON_SN', 45000, null, 'otomatis', 20],
            ['RKT-001', 'Roller CVT 13g', 'Mesin', 'pcs', 'NON_SN', 28000, null, 'otomatis', 15],
            ['RKT-002', 'Vanbelt', 'Mesin', 'pcs', 'NON_SN', 85000, null, 'otomatis', 6],
            ['RNR-001', 'Rantai & Gir Set', 'Mesin', 'set', 'NON_SN', 125000, null, 'otomatis', 4],
            ['BAN-001', 'Ban Tubeless 90/80', 'Ban', 'pcs', 'NON_SN', 210000, 10, 'otomatis', 6],
            ['LMP-001', 'Lampu LED H4', 'Lampu', 'pcs', 'NON_SN', 65000, null, 'otomatis', 10],
        ];

        $settings = TokoSetting::utama();
        $harga = app(HargaService::class);
        $barangMap = [];
        foreach ($barangDef as $d) {
            $barang = Barang::firstOrCreate(['kode_barang' => $d[0]], [
                'tipe_item' => 'barang',
                'kode_barang' => $d[0],
                'nama' => $d[1],
                'kategori_id' => $kategori[$d[2]],
                'satuan' => $d[3],
                'sn_atau_non_sn' => $d[4],
                'margin_override' => $d[6],
                'mode_harga_jual' => $d[7],
                'harga_jual' => $harga->hitungHargaJual($d[5], $d[6] ?? $settings->margin_default),
                'harga_beli_terakhir' => $d[5],
                'lokasi_rak' => 'Rak ' . $d[2][0] . '1',
                'status' => 'Dijual & Untuk Servis',
            ]);
            $barangMap[$d[0]] = $barang;
        }

        // ---- Pembelian: tersebar di SELURUH bulan berjalan (konsisten di tanggal berapa pun hari ini) ----
        $pembelian = app(PembelianService::class);
        $hariIni = now();
        $bulan = $hariIni->copy()->startOfMonth();
        $jadwal = [
            1  => ['OLI-001', 'OLI-002', 'FLT-001'],
            3  => ['BUS-001', 'RKT-001'],
            5  => ['AKI-001', 'AKI-002'],
            8  => ['KPR-001', 'KPR-002', 'FLT-002'],
            11 => ['RKT-002', 'RNR-001'],
            14 => ['BAN-001', 'LMP-001'],
            17 => ['OLI-001', 'OLI-002'],
            20 => ['FLT-001', 'FLT-002', 'BUS-001'],
            23 => ['KPR-001', 'RKT-001', 'BAN-001'],
        ];

        // seed RNG agar data contoh stabil antar 'migrate:fresh --seed'
        mt_srand(31082026);

        $no = 0;
        foreach ($jadwal as $tglHari => $kodes) {
            $tanggal = $bulan->copy()->addDays($tglHari - 1)->setTime(rand(8, 10), rand(0, 59));
            $items = [];
            foreach ($kodes as $kode) {
                $b = $barangMap[$kode];
                $qty = rand(4, 9);
                $def = collect($barangDef)->first(fn ($x) => $x[0] === $kode);
                $hargaBeli = (int) round($def[5] * (1 + rand(-4, 4) / 100));
                $items[] = [
                    'barang_id' => $b->id,
                    'harga_beli' => $hargaBeli,
                    'qty' => $qty,
                    'diskon_item' => 0,
                    'no_sn' => $b->isSn()
                        ? ($kode === 'AKI-001' ? 'GS35-' . str_pad((string) rand(1, 99), 3, '0', STR_PAD_LEFT) : 'YU45-' . str_pad((string) rand(1, 99), 3, '0', STR_PAD_LEFT))
                        : null,
                ];
            }
            $jenis = $no % 3 === 2 ? 'hutang' : 'cash';
            $diskonTotal = (rand(0, 1) === 1) ? rand(5000, 15000) : 0;
            $subtotal = array_sum(array_map(fn ($it) => ($it['harga_beli'] * $it['qty']) - $it['diskon_item'], $items));
            $total = max(0, $subtotal - $diskonTotal);
            try {
                $pembelian->simpan([
                    'supplier_id' => $suppliers[$no % 3],
                    'tanggal_transaksi' => $tanggal->format('Y-m-d H:i'),
                    'jenis' => $jenis,
                    'diskon_total' => $diskonTotal,
                    'bayar_atau_dp' => $jenis === 'cash' ? $total : ((rand(0, 1) === 1) ? min($total, rand(100000, 300000)) : 0),
                    'items' => $items,
                ], 'manual');
            } catch (\Throwable $e) {
                $this->command?->warn('Pembelian demo di-skip: ' . $e->getMessage());
            }
            $no++;
        }

        // ---- Penjualan manual: 3 nota di tanggal yg stoknya sudah masuk (hari 10-13) ----
        $penjualan = app(PenjualanService::class);
        $mapManual = [10 => ['OLI-001', 2], 12 => ['FLT-001', 1], 13 => ['AKI-001', 1]];
        foreach ($mapManual as $tglHari => [$kode, $qty]) {
            $b = $barangMap[$kode];
            try {
                $penjualan->simpanManual(
                    $bulan->copy()->addDays($tglHari - 1)->setTime(rand(9, 16), rand(0, 59)),
                    [['barang_id' => $b->id, 'qty' => $qty, 'harga_jual' => $b->harga_jual]],
                );
            } catch (\Throwable $e) {
                $this->command?->warn('Penjualan manual demo di-skip (' . $kode . '): ' . $e->getMessage());
            }
        }

        // ---- Rekonstruksi: 1 batch TERSIMPAN + 1 batch DRAFT (bisa dicoba Review) ----
        $generate = app(GenerateService::class);
        $mulai = $bulan->copy();
        $selesai = $bulan->copy()->endOfMonth();

        // a) batch tersimpan (pembukuan resmi) — target 1.5 jt (fallback: semaksimal stok)
        mt_srand(12092026);
        try {
            $res1 = $generate->generate($mulai->copy(), $selesai->copy(), 1500000, [], false);
            if (($res1['status'] ?? '') === 'kapasitas_kurang') {
                $res1 = $generate->generate($mulai->copy(), $selesai->copy(), 1500000, [], true);
            }
            $batch1 = $res1['batch'] ?? null;
            if ($batch1) {
                $generate->simpanDraft($batch1->id);
                $this->command?->info('Batch rekonstruksi TERSIMPAN: #' . $batch1->id . ' (total ' . rupiah($batch1->total_generate) . ').');
            }
        } catch (\Throwable $e) {
            $this->command?->warn('Generate tersimpan demo di-skip: ' . $e->getMessage());
        }

        // b) batch draft utk percobaan Review/Generate Ulang
        mt_srand(15092026);
        try {
            $res2 = $generate->generate($mulai->copy(), $selesai->copy(), 4500000, [], false);
            if (($res2['status'] ?? '') === 'kapasitas_kurang') {
                $res2 = $generate->generate($mulai->copy(), $selesai->copy(), 4500000, [], true);
            }
            $batch2 = $res2['batch'] ?? null;
            if ($batch2 && ($batch2->status ?? '') === 'draft') {
                $this->command?->info('Batch rekonstruksi DRAFT utk dicoba review: #' . $batch2->id . ' (total ' . rupiah($batch2->total_generate) . ').');
            }
        } catch (\Throwable $e) {
            $this->command?->warn('Generate draft demo di-skip: ' . $e->getMessage());
        }

        $this->command?->info('Demo data siap: ' . count($barangMap) . ' barang, ' . count($suppliers) . ' supplier, pembelian & penjualan contoh di bulan berjalan.');
    }
}
