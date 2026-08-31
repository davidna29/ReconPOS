<?php

namespace Database\Seeders;

use App\Models\Barang;
use App\Models\KategoriBarang;
use App\Models\Supplier;
use App\Models\TokoSetting;
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

        // Pembelian tersebar di bulan berjalan (sampai hari ini) — sumber kartu stok masuk
        $pembelian = app(PembelianService::class);
        $hariIni = now();
        $jadwal = [
            1 => ['OLI-001', 'OLI-002', 'FLT-001'],
            3 => ['BUS-001', 'RKT-001'],
            5 => ['AKI-001', 'AKI-002'],
            8 => ['KPR-001', 'KPR-002', 'FLT-002'],
            11 => ['RKT-002', 'RNR-001'],
            14 => ['BAN-001', 'LMP-001'],
            17 => ['OLI-001', 'OLI-002'],
            20 => ['FLT-001', 'FLT-002', 'BUS-001'],
            23 => ['KPR-001', 'RKT-001', 'BAN-001'],
        ];

        $no = 0;
        foreach ($jadwal as $tglHari => $kodes) {
            if ($tglHari > $hariIni->day) {
                continue;
            }
            $tanggal = $hariIni->copy()->startOfMonth()->addDays($tglHari - 1)->setTime(rand(8, 10), rand(0, 59));
            $items = [];
            foreach ($kodes as $kode) {
                $b = $barangMap[$kode];
                $qty = rand(3, 8);
                $hargaBeli = $b->harga_beli_terakhir > 0 ? $b->harga_beli_terakhir : 0;
                $def = collect($barangDef)->first(fn ($x) => $x[0] === $kode);
                $hargaBeli = (int) round($def[5] * (1 + rand(-5, 5) / 100));
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
                // skip invoice yang gagal (mis. no invoice bentrok) — demo tetap berjalan
                $this->command?->warn('Pembelian demo di-skip: ' . $e->getMessage());
            }
            $no++;
        }

        // 2 penjualan manual di bulan berjalan (sumber manual — terhitung di target generate)
        $penjualan = app(PenjualanService::class);
        foreach ([2 => 'OLI-001', 4 => 'BUS-001'] as $tglHari => $kode) {
            if ($tglHari > $hariIni->day) {
                continue;
            }
            $b = $barangMap[$kode];
            try {
                $penjualan->simpanManual(
                    $hariIni->copy()->startOfMonth()->addDays($tglHari - 1)->setTime(rand(9, 16), rand(0, 59)),
                    [['barang_id' => $b->id, 'qty' => 1, 'harga_jual' => $b->harga_jual]],
                );
            } catch (\Throwable) {
                // stok mungkin belum masuk — abaikan
            }
        }

        $this->command?->info('Demo data siap: ' . count($barangMap) . ' barang, ' . count($suppliers) . ' supplier, pembelian & penjualan contoh di bulan berjalan.');
    }
}
