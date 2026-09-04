<?php

namespace App\Services;

use App\Models\Barang;
use App\Models\BatchGenerate;
use App\Models\KartuStok;
use App\Models\ReferensiSn;
use App\Models\TokoSetting;
use App\Models\TransaksiPenjualan;
use App\Models\TransaksiPenjualanItem;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Algoritma Generate Transaksi Otomatis (PRD 6 & 7).
 *
 * Prinsip: rekonstruksi estimasi penjualan dari stok masuk riil + target omset,
 * tanpa pernah membuat stok minus, mematuhi jam operasional & hari libur,
 * qty SN = 1 & unik, variasi harga tidak di bawah harga beli, total dalam toleransi.
 */
class GenerateService
{
    /** Cache harga per barang selama satu proses generate (bukan static — hindari bocor antar proses/test). */
    private array $hargaCacheBarang = [];

    public function __construct(
        private PenjualanService $penjualan,
        private HargaService $harga,
        private StockService $stock,
    ) {}

    /**
     * Jalankan generate. Menghapus & menimpa draft periode yang sama (aturan keras #3).
     *
     * @return array{batch?: BatchGenerate, status: string, +...}
     */
    public function generate(
        Carbon $mulai,
        Carbon $selesai,
        int $targetOmset,
        array $params = [],
        bool $force = false,
    ): array {
        if ($mulai->greaterThan($selesai)) {
            throw new InvalidArgumentException('Tanggal mulai harus sebelum tanggal selesai.');
        }
        if ($targetOmset <= 0) {
            throw new InvalidArgumentException('Target omset harus lebih dari 0.');
        }

        // cache harga instance — bersihkan per proses generate (hindari harga basi antar-run/test)
        $this->hargaCacheBarang = [];

        $settings = TokoSetting::utama();
        $def = $settings->parameterGenerate();
        $params = array_merge($def, [
            'jam_buka' => $settings->jam_buka,
            'jam_tutup' => $settings->jam_tutup,
            'hari_libur' => $settings->hari_libur ?? ['dates' => [], 'weekly' => []],
            'excluded_item_ids' => [],
        ], $params);

        // 1. sisa target = target - transaksi tersimpan pada periode (manual + rekonstruksi)
        $existingTotal = (int) TransaksiPenjualan::where('status', 'tersimpan')
            ->whereBetween('tanggal_waktu', [$mulai->copy()->startOfDay(), $selesai->copy()->endOfDay()])
            ->sum('total');
        $sisaTarget = max(0, $targetOmset - $existingTotal);

        // Hapus draft lama periode sama (menimpa, aturan keras #3)
        $this->hapusDraftPeriode($mulai->toDateString(), $selesai->toDateString());

        if ($sisaTarget <= 0) {
            throw new InvalidArgumentException(
                'Target omset sudah tercapai dari transaksi yang sudah ada pada periode tsb (Rp ' . uang($existingTotal) . '). Kurangi target atau ubah periode.'
            );
        }

        // 2. Timeline ketersediaan stok & kapasitas maksimum
        [$timeline, $kapasitas, $closedDays, $diagnose] = $this->bangunTimeline(
            $mulai->copy()->startOfDay(),
            $selesai->copy()->endOfDay(),
            (array) ($params['excluded_item_ids'] ?? []),
        );

        // Log diagnosa untuk produksi
        Log::info('Generate diagnose', [
            'periode' => $mulai->toDateString() . ' - ' . $selesai->toDateString(),
            'target' => $targetOmset,
            'existing' => $existingTotal,
            'sisaTarget' => $sisaTarget,
            'kapasitasPeak' => $diagnose['kapasitasPeak'],
            'kapasitasFinal' => $diagnose['kapasitasFinal'],
            'kapasitasDipakai' => $kapasitas,
            'totalWeight' => $diagnose['totalWeight'],
            'openDays' => $diagnose['openDays'],
            'closedDays' => count($closedDays),
        ]);

        // 3. Cek kapasitas: warning + 2 opsi (lanjut semaksimal / batal)
        if ($kapasitas < $sisaTarget && ! $force) {
            return [
                'status' => 'kapasitas_kurang',
                'kapasitas' => $kapasitas,
                'target' => $sisaTarget,
                'pesan' => 'Nilai jual seluruh stok yang tersedia pada periode ini (Rp '
                    . uang($kapasitas) . ') lebih kecil dari sisa target (Rp ' . uang($sisaTarget) . ').',
            ];
        }

        $efektifTarget = min($sisaTarget, $kapasitas);
        $kapasitasKurang = $kapasitas < $sisaTarget;

        // 4-8. Alokasikan & bangun transaksi
        $transaksi = $this->bangunTransaksi(
            $mulai->copy(),
            $selesai->copy(),
            $efektifTarget,
            $timeline,
            (int) ($params['nota_min'] ?? 1),
            (int) ($params['nota_max'] ?? 8),
            (int) ($params['toleransi_persen'] ?? 2),
            (int) ($params['maks_qty_item_per_nota'] ?? 3),
            (int) ($params['maks_sku_per_nota'] ?? 1),
            (int) ($params['variasi_min'] ?? -10),
            (int) ($params['variasi_max'] ?? 0),
            (string) $params['jam_buka'],
            (string) $params['jam_tutup'],
            $closedDays,
            $kapasitasKurang,
        );

        $total = 0;
        foreach ($transaksi as $t) {
            $total += $t['total'];
        }

        if (count($transaksi) === 0) {
            throw new InvalidArgumentException(
                'Tidak ada barang yang bisa dijual pada periode ini (stok tersedia 0 atau semua barang dikecualikan).'
            );
        }

        // 9. Simpan draft (tanpa efek stok - reversible, PRD 9)
        $batch = BatchGenerate::create([
            'periode_mulai' => $mulai->toDateString(),
            'periode_selesai' => $selesai->toDateString(),
            'target_omset' => $targetOmset,
            'parameter_json' => $params + [
                'existing_total' => $existingTotal,
                'sisa_target' => $sisaTarget,
                'kapasitas' => $kapasitas,
                'kapasitasPeak' => $diagnose['kapasitasPeak'],
                'kapasitasFinal' => $diagnose['kapasitasFinal'],
            ],
            'status' => 'draft',
            'total_generate' => $total,
            'selisih' => $total - $targetOmset,
            'keterangan' => $kapasitasKurang
                ? 'Kapasitas stok kurang dari target — generate semaksimal stok yang ada.'
                : null,
        ]);

        foreach ($transaksi as $t) {
            $trx = TransaksiPenjualan::create([
                'ref_transaksi' => $t['ref'],
                'tanggal_waktu' => $t['tanggal_waktu'],
                'customer_id' => 0,
                'jenis_transaksi' => 'cash',
                'tipe_pembayaran' => 'cash',
                'sumber' => 'rekonstruksi',
                'status' => 'draft',
                'batch_generate_id' => $batch->id,
                'total' => $t['total'],
            ]);
            foreach ($t['items'] as $it) {
                TransaksiPenjualanItem::create([
                    'transaksi_penjualan_id' => $trx->id,
                    'barang_id' => $it['barang_id'],
                    'qty' => $it['qty'],
                    'harga_jual' => $it['harga_jual'],
                    'no_sn' => $it['no_sn'],
                    'subtotal' => $it['subtotal'],
                ]);
            }
        }

        return [
            'status' => 'ok',
            'batch' => $batch,
            'kapasitas' => $kapasitas,
            'kapasitas_kurang' => $kapasitasKurang,
            'total' => $total,
            'selisih' => $total - $targetOmset,
        ];
    }

    /**
     * Diagnosa produksi: hitung timeline, kapasitas, weight tanpa generate.
     * Berguna untuk artisan generate:diagnose atau debug.
     */
    public function diagnose(Carbon $mulai, Carbon $selesai, array $excluded = []): array
    {
        [$timeline, $kapasitas, $closedDays, $diag] = $this->bangunTimeline($mulai, $selesai, $excluded);
        // hitung weights juga
        $days = [];
        for ($d = $mulai->copy(); $d->lte($selesai); $d->addDay()) {
            $days[$d->toDateString()] = $d->copy();
        }
        $weights = [];
        $totalWeight = 0;
        foreach ($days as $key => $day) {
            if (isset($closedDays[$key])) {
                continue;
            }
            $w = (int) KartuStok::whereIn('barang_id', array_keys($timeline ?: [0]))
                ->where('tanggal', '>=', $day->copy()->startOfDay())
                ->where('tanggal', '<=', $day->copy()->endOfDay())
                ->where('qty', '>', 0)
                ->sum('qty');
            $weights[$key] = $w;
            $totalWeight += $w;
        }
        // fallback uniform jika totalWeight 0 tapi ada avail
        $hasAvail = false;
        foreach ($timeline as $perDay) {
            foreach ($perDay as $q) {
                if ($q > 0) {
                    $hasAvail = true;
                    break 2;
                }
            }
        }
        return array_merge($diag, [
            'timeline' => $timeline,
            'closedDays' => $closedDays,
            'weights' => $weights,
            'totalWeight' => $totalWeight,
            'hasAvail' => $hasAvail,
            'kapasitas' => $kapasitas,
        ]);
    }

    /**
     * Bangun ketersediaan per hari + kapasitas maksimum.
     *
     * @return array{0: array, 1: int, 2: array, 3: array} [timeline, kapasitas, closedDays, diagnose]
     *   timeline = [barang_id => [dayKey => int qty tersedia di awal hari]]
     */
    private function bangunTimeline(Carbon $mulai, Carbon $selesai, array $excluded): array
    {
        $barangs = Barang::where('tipe_item', 'barang')
            ->whereNotIn('id', $excluded)
            ->get();

        // Semua mutasi stok sampai akhir periode (masuk + keluar), urut kronologis
        $events = KartuStok::where('tanggal', '<=', $selesai)
            ->orderBy('tanggal')
            ->get()
            ->groupBy('barang_id');

        $days = [];
        for ($d = $mulai->copy(); $d->lte($selesai); $d->addDay()) {
            $days[$d->toDateString()] = $d->copy(); // salinan utk tiap hari (hindari alias object)
        }

        $timeline = [];
        $kapasitasPeak = 0;
        $kapasitasFinal = 0;
        $closedDays = [];

        $settings = TokoSetting::utama();
        $libur = $settings->hari_libur ?? ['dates' => [], 'weekly' => []];

        // Preload barang map
        $barangMap = $barangs->keyBy('id');

        foreach ($barangs as $barang) {
            $ev = $events->get($barang->id, collect());
            $masukPerHari = []; // qty masuk per hari
            $penjualanHari = []; // qty keluar per hari (nilai positif)
            $initialBalance = 0;

            foreach ($ev as $row) {
                $tgl = Carbon::parse($row->tanggal);
                $key = $tgl->toDateString();
                // Pisahkan stok sebelum periode sebagai initial
                if ($tgl->lt($mulai)) {
                    $initialBalance += (int) $row->qty;
                } else {
                    if ($row->qty > 0) {
                        $masukPerHari[$key] = ($masukPerHari[$key] ?? 0) + $row->qty;
                    } else {
                        $penjualanHari[$key] = ($penjualanHari[$key] ?? 0) + abs($row->qty);
                    }
                }
            }

            $balance = $initialBalance;
            $peakAvail = 0;
            foreach ($days as $key => $day) {
                // ketersediaan di awal hari = saldo sebelumnya - penjualan hari tsb (konservatif)
                // penjualan hari tsb sudah terjadi (historis) sehingga mengurangi avail untuk generate
                $availAwal = $balance - ($penjualanHari[$key] ?? 0);
                // avail tidak boleh negatif (stok minus historis ditampilkan tapi tidak dipakai generate)
                $availAwal = max(0, $availAwal);
                $timeline[$barang->id][$key] = $availAwal;
                $balance += ($masukPerHari[$key] ?? 0) - ($penjualanHari[$key] ?? 0);
                if ($availAwal > $peakAvail) {
                    $peakAvail = $availAwal;
                }
            }
            // kapasitas periode = peak avail x harga jual MASTER (konservatif: tidak termasuk masuk hari terakhir)
            // dan final balance (sisa akhir) x harga — ambil yang lebih kecil sebagai kapasitas real (tidak jual stok hari terakhir)
            if ($peakAvail > 0) {
                $kapasitasPeak += $peakAvail * (int) $barang->harga_jual;
            }
            // final balance = stock tersisa di akhir periode (termasuk masuk hari terakhir, tapi konservatif peak lebih akurat)
            $finalAvail = max(0, $balance - ($masukPerHari[$selesai->toDateString()] ?? 0));
            // Jika ada masuk di hari terakhir, finalAvail sudah dikurangi di atas agar tidak jual stok H terakhir di hari itu
            // Alternatif: gunakan $balance yang sudah termasuk semua, tapi peak sudah benar untuk kapasitas generate
            if ($finalAvail > 0) {
                $kapasitasFinal += $finalAvail * (int) $barang->harga_jual;
            }
        }

        // Kapasitas dipakai = peak (yang tidak menjual stok H di hari H) — paling akurat untuk generate dalam periode
        // Namun untuk diagnosa, simpan keduanya; pakai peak sebagai kapasitas utama
        $kapasitas = $kapasitasPeak;
        // Jika peak 0 tapi final >0 (kasus masuk hanya di hari terakhir), fallback ke final
        if ($kapasitas === 0 && $kapasitasFinal > 0) {
            $kapasitas = $kapasitasFinal;
        }

        // hari libur/tutup toko
        foreach ($days as $key => $day) {
            if ($this->isHariLibur($day, $libur)) {
                $closedDays[$key] = true;
            }
        }

        $diagnose = [
            'kapasitasPeak' => $kapasitasPeak,
            'kapasitasFinal' => $kapasitasFinal,
            'totalWeight' => 0, // diisi caller jika perlu
            'openDays' => count($days) - count($closedDays),
        ];

        return [$timeline, $kapasitas, $closedDays, $diagnose];
    }

    private function isHariLibur(Carbon $day, array $libur): bool
    {
        if (in_array($day->toDateString(), (array) ($libur['dates'] ?? []), true)) {
            return true;
        }
        $weekly = (array) ($libur['weekly'] ?? []);
        $name = strtolower($day->format('D')); // Mon..Sun
        foreach ($weekly as $w) {
            if (strtolower((string) $w) === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bangun daftar transaksi. Menghasilkan per-hari proporsional stok masuk,
     * mematuhi aturan keras (qty <= stok, SN qty=1 & unik, jam operasional, variasi >= harga beli).
     *
     * @return array<int, array{ref: string, tanggal_waktu: Carbon, items: array, total: int}>
     */
    private function bangunTransaksi(
        Carbon $mulai,
        Carbon $selesai,
        int $target,
        array $timeline,
        int $notaMin,
        int $notaMax,
        int $toleransi,
        int $maksQtyPerNota,
        int $maksSkuPerNota,
        int $variasiMin,
        int $variasiMax,
        string $jamBuka,
        string $jamTutup,
        array $closedDays,
        bool $kapasitasKurang,
    ): array {
        $days = [];
        for ($d = $mulai->copy(); $d->lte($selesai); $d->addDay()) {
            $days[$d->toDateString()] = $d->copy();
        }

        // bobot harian dari stok tersedia di awal hari (avail), bukan stok masuk hari itu.
        // Alasan: stok masuk H baru bisa dipakai H+1 (konservatif), jadi bobot berat harus di hari
        // dimana stok benar-benar tersedia untuk dijual, bukan hari masuknya.
        $weights = [];
        $totalWeight = 0;
        foreach ($days as $key => $day) {
            if (isset($closedDays[$key])) {
                continue;
            }
            $w = 0;
            foreach ($timeline as $perDay) {
                $w += (int) ($perDay[$key] ?? 0);
            }
            $weights[$key] = $w;
            $totalWeight += $w;
        }

        if ($totalWeight <= 0) {
            throw new InvalidArgumentException(
                'Tidak ada stok masuk pada periode ini — generate tidak bisa berjalan. Tambahkan stok lewat Transaksi Pembelian terlebih dahulu.'
            );
        }

        // ---- Model unit-pool: tiap unit fisik dihitung SEKALI, tanpa dobel jual ----
        // avail[barang][day] = jumlah unit (belum terjual) yg tersedia di awal hari tsb
        // unitSisa[barang]  = total unit tersisa di periode (peak availability)
        $avail = [];
        $unitSisa = [];
        $daysItem = []; // hari yg valid utk tiap barang = hari dgn avail > 0
        // Preload barang map untuk hindari N+1
        $barangMap = Barang::whereIn('id', array_keys($timeline))->get()->keyBy('id');
        foreach ($timeline as $barangId => $perDay) {
            $peak = 0;
            $daysItem[$barangId] = [];
            foreach ($perDay as $key => $qty) {
                if ($qty > 0) {
                    $avail[$barangId][$key] = (int) $qty;
                    $daysItem[$barangId][] = $key;
                    if ($qty > $peak) {
                        $peak = (int) $qty;
                    }
                }
            }
            $unitSisa[$barangId] = $peak;
        }

        // SN tersedia global (1 nomor hanya 1x — aturan keras)
        $snPool = [];
        foreach ($timeline as $barangId => $_) {
            $b = $barangMap->get($barangId);
            if ($b && $b->isSn()) {
                $snPool[$barangId] = ReferensiSn::where('barang_id', $barangId)
                    ->where('status', 'tersedia')
                    ->orderBy('id')
                    ->pluck('no_sn')
                    ->all();
                // Jika stok SN habis (pool kosong) tapi timeline bilang avail >0, anggap 0
                if (count($snPool[$barangId]) === 0) {
                    $unitSisa[$barangId] = 0;
                } else {
                    // Batasi unitSisa SN dengan jumlah SN tersedia
                    $unitSisa[$barangId] = min($unitSisa[$barangId], count($snPool[$barangId]));
                }
            }
        }

        // ---- Alokasi target per hari (proporsional bobot + jitter) ----
        $openDays = array_keys($weights);
        $dayTargets = [];
        $remainingTarget = $target;
        // jitter per hari
        $jitteredWeights = [];
        $jitterSum = 0;
        foreach ($openDays as $key) {
            $jitter = (rand(80, 120) / 100); // 0.8 - 1.2
            $jw = $weights[$key] * $jitter;
            $jitteredWeights[$key] = $jw;
            $jitterSum += $jw;
        }
        $allocated = 0;
        $lastKey = end($openDays);
        foreach ($openDays as $key) {
            if ($key === $lastKey) {
                $dayTargets[$key] = $remainingTarget - $allocated;
            } else {
                $portion = $jitteredWeights[$key] / max(1, $jitterSum);
                $dayTargets[$key] = (int) round($target * $portion);
                $allocated += $dayTargets[$key];
            }
            // Pastikan tidak negatif
            $dayTargets[$key] = max(0, $dayTargets[$key]);
        }
        // Normalisasi jika rounding menyebabkan sum != target (sudah ditangani via lastKey, tapi jaga)
        $sumDayTargets = array_sum($dayTargets);
        if ($sumDayTargets !== $target && $sumDayTargets > 0) {
            $diff = $target - $sumDayTargets;
            $dayTargets[$lastKey] = max(0, $dayTargets[$lastKey] + $diff);
        }

        $transaksi = [];
        $notaSeq = 0;

        // Ketersediaan per hari = timeline hari itu - konsumsi simulasi s.d. hari itu (running balance)
        $simSold = [];              // total unit barang terjual simulasi (seluruh hari s.d. sekarang)
        $soldToday = [];            // soldToday[barang][day] — kuota hari itu yg sudah dipakai simulasi

        $sales = []; // [dayKey][] = ['barang_id', 'qty', 'price', 'no_sn']

        // ---- Generate per hari ----
        $debugPerHari = [];
        foreach ($openDays as $dayKey) {
            $dayTarget = $dayTargets[$dayKey] ?? 0;
            if ($dayTarget <= 0) {
                continue;
            }
            $sisaDay = $dayTarget;
            $attempts = 0;
            $maxAttemptsPerDay = 5000; // batas aman per hari
            $awalCount = count($sales[$dayKey] ?? []);
            while ($sisaDay > 0 && $attempts++ < $maxAttemptsPerDay) {
                // barang dengan unit tersisa DAN punya avail hari ini
                $candidates = [];
                foreach ($unitSisa as $barangId => $u) {
                    if ($u <= 0) {
                        continue;
                    }
                    if (empty($daysItem[$barangId])) {
                        continue;
                    }
                    $hariBatas = ($timeline[$barangId][$dayKey] ?? 0) - ($simSold[$barangId] ?? 0) - ($soldToday[$barangId][$dayKey] ?? 0);
                    if ($hariBatas > 0) {
                        $candidates[$barangId] = $hariBatas;
                    }
                }
                if (count($candidates) === 0) {
                    break; // stok hari ini habis
                }

                // Pilih barang: weighted by hariBatas (stok hari ini)
                $totalHariBatas = array_sum($candidates);
                $roll = rand(1, max(1, $totalHariBatas));
                $bid = null;
                foreach ($candidates as $cand => $hb) {
                    $roll -= $hb;
                    if ($roll <= 0) {
                        $bid = $cand;
                        break;
                    }
                }
                $bid ??= array_key_first($candidates);

                $b = $barangMap->get($bid);
                if (! $b) {
                    $unitSisa[$bid] = 0;
                    continue;
                }

                // Harga
                [$hargaJual, $hargaBeli] = $this->hargaBarang($bid, $days[$dayKey]);
                $price = $this->variasiHarga($hargaJual, $hargaBeli, $variasiMin, $variasiMax);

                // Tentukan qty: acak 1..max yang terjangkau
                $hariBatas = $candidates[$bid];
                $maxQtyHari = min($hariBatas, $unitSisa[$bid], $maksQtyPerNota);
                if ($b->isSn()) {
                    if (count($snPool[$bid] ?? []) === 0) {
                        $unitSisa[$bid] = 0;
                        continue;
                    }
                    $maxQtyHari = 1;
                }
                if ($maxQtyHari <= 0) {
                    $soldToday[$bid][$dayKey] = PHP_INT_MAX; // tandai hari habis untuk barang ini
                    continue;
                }

                // Batasi qty agar tidak melebihi sisaDay terlalu jauh, tapi tetap acak
                $affordableQty = $maxQtyHari;
                if ($price > 0 && $sisaDay < $price * $maxQtyHari) {
                    $affordableQty = max(1, min($maxQtyHari, (int) floor($sisaDay / $price) + rand(0, 1)));
                    // Jika sisaDay < price, tetap 1 (overshoot diperbolehkan, nanti disesuaikan)
                    if ($sisaDay < $price) {
                        $affordableQty = 1;
                    }
                }
                // Random qty 1..affordableQty untuk variasi
                $qty = $affordableQty > 1 ? rand(1, $affordableQty) : 1;
                // Pastikan tidak melebihi hariBatas
                $qty = min($qty, $hariBatas, $unitSisa[$bid]);

                if ($qty <= 0) {
                    $soldToday[$bid][$dayKey] = PHP_INT_MAX;
                    continue;
                }

                // SN handling
                $noSn = null;
                if ($b->isSn()) {
                    $qty = 1;
                    $noSn = array_pop($snPool[$bid]);
                    if ($noSn === null) {
                        $unitSisa[$bid] = 0;
                        continue;
                    }
                }

                $sales[$dayKey][] = [
                    'barang_id' => $bid,
                    'qty' => $qty,
                    'price' => $price,
                    'no_sn' => $noSn,
                ];
                $unitSisa[$bid] -= $qty;
                $simSold[$bid] = ($simSold[$bid] ?? 0) + $qty;
                $soldToday[$bid][$dayKey] = ($soldToday[$bid][$dayKey] ?? 0) + $qty;
                $sisaDay -= $qty * $price;
                // Jika sisaDay negatif (overshoot), tetap lanjut tapi akan dikoreksi penyesuaianAkhir
                // Untuk hari ini, jika overshoot besar, break hari
                if ($sisaDay <= - ($price * 2)) {
                    break;
                }
            }

            $debugPerHari[$dayKey] = [
                'target' => $dayTarget,
                'sisaDayAwal' => $sisaDay,
                'generated' => count($sales[$dayKey] ?? []) - $awalCount,
                'sisaDayAkhir' => $sisaDay,
            ];
        }

        Log::debug('Generate per-hari debug', ['dayTargets' => $dayTargets, 'debugPerHari' => $debugPerHari, 'weights' => $weights]);
        // Jika masih ada sisa global yang besar (karena per-hari target tidak tercapai akibat stok hari habis),
        // lakukan pengisian global tambahan untuk menutup sisa (memaksimalkan)
        $totalSales = 0;
        foreach ($sales as $daySales) {
            foreach ($daySales as $s) {
                $totalSales += $s['qty'] * $s['price'];
            }
        }
        $sisaGlobal = $target - $totalSales;
        if ($sisaGlobal > 0 && ! $kapasitasKurang) {
            // Coba isi sisa global dengan loop tambahan (maksimal 5000 iterasi)
            $guard = 0;
            while ($sisaGlobal > 0 && $guard++ < 5000) {
                $candidates = [];
                foreach ($unitSisa as $bid => $u) {
                    if ($u > 0) {
                        // Cari hari dengan sisa avail terbesar untuk barang ini
                        $bestDay = null;
                        $bestAvail = 0;
                        foreach ($openDays as $dk) {
                            $hb = ($timeline[$bid][$dk] ?? 0) - ($simSold[$bid] ?? 0) - ($soldToday[$bid][$dk] ?? 0);
                            if ($hb > $bestAvail) {
                                $bestAvail = $hb;
                                $bestDay = $dk;
                            }
                        }
                        if ($bestDay !== null && $bestAvail > 0) {
                            $candidates[$bid] = ['day' => $bestDay, 'avail' => $bestAvail];
                        }
                    }
                }
                if (empty($candidates)) {
                    break;
                }
                // Pilih barang dengan avail terbesar
                uasort($candidates, fn($a,$b) => $b['avail'] <=> $a['avail']);
                $bid = array_key_first($candidates);
                $dayKey = $candidates[$bid]['day'];
                $b = $barangMap->get($bid);
                if (!$b) {
                    $unitSisa[$bid]=0;
                    continue;
                }
                [$hargaJual, $hargaBeli] = $this->hargaBarang($bid, $days[$dayKey]);
                $price = $this->variasiHarga($hargaJual, $hargaBeli, $variasiMin, $variasiMax);
                $hariBatas = $candidates[$bid]['avail'];
                $maxQty = min($hariBatas, $unitSisa[$bid], $maksQtyPerNota);
                if ($b->isSn()) {
                    if (empty($snPool[$bid])) {
                        $unitSisa[$bid]=0;
                        continue;
                    }
                    $maxQty=1;
                }
                if ($maxQty <=0) {
                    $soldToday[$bid][$dayKey]=PHP_INT_MAX;
                    continue;
                }
                $qty = $maxQty >1 ? rand(1, $maxQty) : 1;
                $noSn = null;
                if ($b->isSn()) {
                    $noSn = array_pop($snPool[$bid]);
                }
                $sales[$dayKey][] = ['barang_id'=>$bid,'qty'=>$qty,'price'=>$price,'no_sn'=>$noSn];
                $unitSisa[$bid]-=$qty;
                $simSold[$bid]=($simSold[$bid]??0)+$qty;
                $soldToday[$bid][$dayKey]=($soldToday[$bid][$dayKey]??0)+$qty;
                $sisaGlobal -= $qty*$price;
                if ($sisaGlobal < -($price*5)) break;
            }
        }

        // Paketkan penjualan per hari menjadi nota (PRD 6; parametrik):
        // - tiap nota memuat MAKS. maks_sku_per_nota JENIS BARANG berbeda (default 1:
        //   "1 nota = 1-4 ban dari 1 SKU" utk bengkel ban).
        // - Baris non-SN utk SKU yang sama DIGABUNG jadi 1 baris (qty ≤ maks_qty_item_per_nota;
        //   lebih → pecah ke nota lain dengan SKU yang sama). Harga gabungan = rata-rata
        //   tertimbang (pembulatan kecil — penyesuaianAkhir menjaga total dalam toleransi).
        // - Barang SN tetap 1 baris qty=1 + NO_SN unik (tidak digabung).
        // - Maks baris per nota tetap 5.
        [$hBuka] = array_map('intval', explode(':', $jamBuka));
        [$hTutup] = array_map('intval', explode(':', $jamTutup));
        $maxItemsPerNota = 5;
        $maxSku = max(1, min((int) $maksSkuPerNota, $maxItemsPerNota));

        foreach ($sales as $dayKey => $daySales) {
            $day = $days[$dayKey];

            // kelompokkan entri per SKU (urutan kemunculan dipertahankan)
            $buckets = [];
            foreach ($daySales as $c) {
                $buckets[$c['barang_id']][] = $c;
            }

            $notas = [];
            foreach ($buckets as $barangId => $entries) {
                foreach ($entries as $c) {
                    $qty = max(1, min((int) $c['qty'], $maksQtyPerNota));
                    $entri = [
                        'barang_id' => (int) $barangId,
                        'qty' => $qty,
                        'price' => (int) $c['price'],
                        'no_sn' => $c['no_sn'],
                    ];

                    // cari nota: (a) belum penuh baris, (b) boleh memuat SKU ini
                    // (belum ada SKU ini ATAU jumlah SKU dari SKU ini masih < kapasitas garis),
                    // utk SKU yang sama: gabung non-SN bila masih muat qty cap.
                    $idx = null;
                    for ($i = 0; $i < count($notas); $i++) {
                        $n = $notas[$i];
                        if (count($n['items']) >= $maxItemsPerNota) {
                            continue;
                        }
                        $punya = isset($n['barang'][$barangId]);
                        $skuCount = count($n['barang']);
                        if (! $punya && $skuCount >= $maxSku) {
                            continue;
                        }
                        if ($punya) {
                            if ($entri['no_sn'] !== null || $n['qty'][$barangId] + $qty > $maksQtyPerNota) {
                                continue; // SN tak digabung; non-SN tak boleh melewati cap
                            }
                        }
                        $idx = $i;
                        break;
                    }

                    if ($idx === null) {
                        $notas[] = [
                            'items' => [$entri],
                            'barang' => [$barangId => true],
                            'qty' => [$barangId => $qty],
                        ];
                    } else {
                        // gabung ke baris SKU yang sama bila non-SN & muat; selain itu tambah baris baru
                        if ($entri['no_sn'] === null && isset($notas[$idx]['barang'][$barangId])) {
                            // cari baris utk SKU tsb & sesuaikan qty+harga (rata-rata tertimbang)
                            foreach ($notas[$idx]['items'] as &$existing) {
                                if ($existing['barang_id'] === $barangId && $existing['no_sn'] === null) {
                                    $oldQty = (int) $existing['qty'];
                                    $newQty = $oldQty + $qty;
                                    $existing['qty'] = $newQty;
                                    $existing['price'] = (int) round(($oldQty * (int) $existing['price'] + $qty * (int) $entri['price']) / $newQty);
                                    break;
                                }
                            }
                            unset($existing);
                            $notas[$idx]['qty'][$barangId] = $newQty ?? ($notas[$idx]['qty'][$barangId] + $qty);
                        } else {
                            $notas[$idx]['items'][] = $entri;
                            $notas[$idx]['barang'][$barangId] = true;
                            $notas[$idx]['qty'][$barangId] = ($notas[$idx]['qty'][$barangId] ?? 0) + $qty;
                        }
                    }
                }
            }

            // Enforce notaMin: jika notas < notaMin dan masih ada stok hari ini, pecah nota yang ada
            // (mis. nota dengan qty 3 pecah jadi 2 nota qty 1+2) untuk mencapai minimal
            if (count($notas) < $notaMin && count($notas) > 0) {
                // Coba pecah nota terbesar
                while (count($notas) < $notaMax && count($notas) < $notaMin) {
                    // Cari nota dengan item qty >1 yang bisa dipecah
                    $pecahIdx = null;
                    foreach ($notas as $i => $n) {
                        foreach ($n['items'] as $it) {
                            if ($it['qty'] > 1 && $it['no_sn'] === null) {
                                $pecahIdx = $i;
                                break 2;
                            }
                        }
                    }
                    if ($pecahIdx === null) break;
                    // Pecah 1 unit dari nota tersebut ke nota baru
                    $itemIdx = null;
                    foreach ($notas[$pecahIdx]['items'] as $j => $it) {
                        if ($it['qty'] > 1 && $it['no_sn'] === null) {
                            $itemIdx = $j;
                            break;
                        }
                    }
                    if ($itemIdx === null) break;
                    $notas[$pecahIdx]['items'][$itemIdx]['qty'] -= 1;
                    // Buat nota baru dengan 1 unit
                    $newEntri = $notas[$pecahIdx]['items'][$itemIdx];
                    $newEntri['qty'] = 1;
                    // harga tetap
                    $notas[] = [
                        'items' => [$newEntri],
                        'barang' => [$newEntri['barang_id'] => true],
                        'qty' => [$newEntri['barang_id'] => 1],
                    ];
                }
            }

            foreach ($notas as $nb) {
                $items = [];
                $notaTotal = 0;
                foreach ($nb['items'] as $c2) {
                    $sub = $c2['qty'] * $c2['price'];
                    $items[] = [
                        'barang_id' => $c2['barang_id'],
                        'qty' => $c2['qty'],
                        'harga_jual' => $c2['price'],
                        'no_sn' => $c2['no_sn'],
                        'subtotal' => $sub,
                    ];
                    $notaTotal += $sub;
                }
                $waktu = $day->copy()->setTime(rand($hBuka, max($hBuka, $hTutup - 1)), rand(0, 59));
                $notaSeq++;
                $refNota = $this->refBebas($day->format('Ymd'), $notaSeq);
                $transaksi[] = [
                    'ref' => $refNota,
                    'tanggal_waktu' => $waktu,
                    'items' => $items,
                    'total' => $notaTotal,
                ];
            }
        }

        // 8. Penyesuaian akhir: dekatkan total ke target dalam toleransi (harga/qty/drop)
        $transaksi = $this->penyesuaianAkhir($transaksi, $target, $toleransi, $kapasitasKurang, $avail, $notaSeq, $jamBuka, $jamTutup, $variasiMin, $maksQtyPerNota);

        return $transaksi;
    }

    /** Harga jual efektif utk "peek" (tanpa variasi acak). */
    private function hargaV(int $barangId, Carbon $day): int
    {
        [$jual] = $this->hargaBarang($barangId, $day);

        return $jual;
    }

    /**
     * Harga jual & harga beli basis utk generate — memakai MASTER (barang.harga_jual &
     * harga_beli_terakhir), BUKAN histori_harga. Dengan ini tombol "Terapkan Margin" di
     * Pengaturan (yang meng-update master) langsung memengaruhi hasil generate.
     * Variasi & aturan "tidak pernah < harga beli" tetap diterapkan di variasiHarga().
     */
    private function hargaBarang(int $barangId, ?Carbon $day = null): array
    {
        if (isset($this->hargaCacheBarang[$barangId])) {
            return $this->hargaCacheBarang[$barangId];
        }
        $barang = Barang::find($barangId);
        $res = $barang ? [(int) $barang->harga_jual, (int) $barang->harga_beli_terakhir] : [0, 0];
        $this->hargaCacheBarang[$barangId] = $res;

        return $res;
    }

    /** Variasi harga sebagai diskon (variasiMax <= 0), tidak pernah < harga beli (aturan keras). */
    private function variasiHarga(int $hargaJual, int $hargaBeli, int $variasiMin, int $variasiMax): int
    {
        $variasi = rand($variasiMin * 10, $variasiMax * 10) / 10; // pecahan persen
        $harga = (int) floor($hargaJual * (1 + $variasi / 100));
        if ($hargaBeli > 0 && $harga < $hargaBeli) {
            $harga = $hargaBeli;
        }

        return $harga;
    }

    /**
     * Penyesuaian akhir: dekatkan total ke target dalam toleransi (PRD 6 poin 8).
     * Prioritas: (1) naik/turunkan harga jual dalam rentang variasi (fine-tune presisi),
     *            (2) tambah/kurangi qty, (3) top-up nota / drop nota.
     * Kasus kapasitas kurang → selesai apa adanya (semaksimal stok).
     */
    private function penyesuaianAkhir(array $transaksi, int $target, int $toleransi, bool $kapasitasKurang, array &$avail, int &$notaSeq, string $jamBuka, string $jamTutup, int $variasiMin, int $maksQtyPerNota): array
    {
        if ($kapasitasKurang || count($transaksi) === 0) {
            return $transaksi;
        }

        // sisa unit per barang = peak availability (bukan jumlah per hari — hindari hitung ganda)
        $sisaTotal = [];
        foreach ($avail as $barangId => $perDay) {
            $sisaTotal[$barangId] = count($perDay) > 0 ? (int) max($perDay) : 0;
            // Kurangi yang sudah terpakai di transaksi
            foreach ($transaksi as $t) {
                foreach ($t['items'] as $it) {
                    if ($it['barang_id'] == $barangId) {
                        $sisaTotal[$barangId] = max(0, $sisaTotal[$barangId] - $it['qty']);
                    }
                }
            }
        }

        $batasBawah = (int) floor($target * (1 - $toleransi / 100));
        $batasAtas = (int) ceil($target * (1 + $toleransi / 100));

        for ($iter = 0; $iter < 300; $iter++) {
            $total = (int) array_sum(array_column($transaksi, 'total'));
            if ($total >= $batasBawah && $total <= $batasAtas) {
                break;
            }

            if ($total < $batasBawah) {
                $kebutuhan = $batasBawah - $total;
                $diproses = false;

                // 1) naikkan harga item non-SN (maks = harga master) — presisi halus
                for ($idx = count($transaksi) - 1; $idx >= 0 && $kebutuhan > 0; $idx--) {
                    $t = &$transaksi[$idx];
                    $day = Carbon::parse($t['tanggal_waktu']);
                    for ($i = count($t['items']) - 1; $i >= 0 && $kebutuhan > 0; $i--) {
                        $it = &$t['items'][$i];
                        if ($it['no_sn'] !== null) {
                            continue;
                        }
                        [$master] = $this->hargaBarang($it['barang_id'], $day);
                        if ($master <= $it['harga_jual']) {
                            continue;
                        }
                        $naikPerUnit = min($master - $it['harga_jual'], (int) ceil($kebutuhan / max(1, $it['qty'])));
                        if ($naikPerUnit <= 0) {
                            continue;
                        }
                        $it['harga_jual'] += $naikPerUnit;
                        $it['subtotal'] = $it['qty'] * $it['harga_jual'];
                        $kebutuhan -= $naikPerUnit * $it['qty'];
                        $diproses = true;
                    }
                    unset($it);
                    $t['total'] = (int) array_sum(array_column($t['items'], 'subtotal'));
                }

                if ($kebutuhan > 0) {
                    // 2) tambah qty item non-SN (batasi sisaTotal)
                    for ($idx = count($transaksi) - 1; $idx >= 0 && $kebutuhan > 0; $idx--) {
                        $t = &$transaksi[$idx];
                        $dayKey = Carbon::parse($t['tanggal_waktu'])->toDateString();
                        for ($i = 0; $i < count($t['items']) && $kebutuhan > 0; $i++) {
                            $it = &$t['items'][$i];
                            if ($it['no_sn'] !== null) {
                                continue;
                            }
                            // jaga qty per SKU per nota tetap ≤ maks_qty_item_per_nota (realisme nota)
                            if ((int) $it['qty'] >= $maksQtyPerNota) {
                                continue;
                            }
                            $availQty = $avail[$it['barang_id']][$dayKey] ?? 0;
                            $sisa = $sisaTotal[$it['barang_id']] ?? 0;
                            if ($sisa <= 0) {
                                continue;
                            }
                            $tambah = min(max(1, (int) floor($kebutuhan / max(1, $it['harga_jual']))), max($availQty, $sisa), 50, $sisa, max(0, $maksQtyPerNota - (int) $it['qty']));
                            if ($tambah <= 0) {
                                continue;
                            }
                            $it['qty'] += $tambah;
                            $it['subtotal'] = $it['qty'] * $it['harga_jual'];
                            $avail[$it['barang_id']][$dayKey] = max(0, $availQty - $tambah);
                            $sisaTotal[$it['barang_id']] = max(0, $sisa - $tambah);
                            $kebutuhan -= $tambah * $it['harga_jual'];
                            $diproses = true;
                        }
                        unset($it);
                        $t['total'] = (int) array_sum(array_column($t['items'], 'subtotal'));
                    }
                }

                if ($kebutuhan > 0) {
                    // 3) nota top-up — buat batch hingga kebutuhan terpenuhi (bukan 1 nota saja)
                    $topUpCount = 0;
                    while ($kebutuhan > 0 && $topUpCount < 20) {
                        $topUp = $this->buatNotaTopUp($sisaTotal, $avail, $kebutuhan, $notaSeq, $jamBuka, $jamTutup, $maksQtyPerNota);
                        if ($topUp) {
                            $transaksi[] = $topUp;
                            $kebutuhan -= $topUp['total'];
                            $diproses = true;
                            $topUpCount++;
                        } else {
                            break;
                        }
                    }
                }

                if (! $diproses) {
                    break; // tidak ada ruang tambah
                }
            } else {
                $overshoot = $total - $batasAtas;
                $dikurangi = false;

                // 1) turunkan harga item non-SN (min = harga beli) — presisi halus
                for ($idx = count($transaksi) - 1; $idx >= 0 && $overshoot > 0; $idx--) {
                    $t = &$transaksi[$idx];
                    $day = Carbon::parse($t['tanggal_waktu']);
                    for ($i = count($t['items']) - 1; $i >= 0 && $overshoot > 0; $i--) {
                        $it = &$t['items'][$i];
                        if ($it['no_sn'] !== null) {
                            continue;
                        }
                        [$master, $beli] = $this->hargaBarang($it['barang_id'], $day);
                        $floor = max($beli, (int) floor($master * (1 + $variasiMin / 100)));
                        if ($it['harga_jual'] <= $floor) {
                            continue;
                        }
                        $perUnit = min($it['harga_jual'] - $floor, (int) ceil($overshoot / max(1, $it['qty'])));
                        if ($perUnit <= 0) {
                            continue;
                        }
                        $it['harga_jual'] -= $perUnit;
                        $it['subtotal'] = $it['qty'] * $it['harga_jual'];
                        $overshoot -= $perUnit * $it['qty'];
                        $dikurangi = true;
                    }
                    unset($it);
                    $t['total'] = (int) array_sum(array_column($t['items'], 'subtotal'));
                }

                if ($overshoot > 0) {
                    // 2) kurangi qty item (qty > 1)
                    for ($idx = count($transaksi) - 1; $idx >= 0 && $overshoot > 0; $idx--) {
                        $t = &$transaksi[$idx];
                        for ($i = count($t['items']) - 1; $i >= 0 && $overshoot > 0; $i--) {
                            $it = &$t['items'][$i];
                            if ($it['no_sn'] !== null || $it['qty'] <= 1) {
                                continue;
                            }
                            $oldQty = $it['qty'];
                            $qtyBaru = max(1, $oldQty - max(1, (int) ceil($overshoot / max(1, $it['harga_jual']))));
                            if ($qtyBaru < $oldQty) {
                                $reduced = $oldQty - $qtyBaru;
                                $it['qty'] = $qtyBaru;
                                $it['subtotal'] = $qtyBaru * $it['harga_jual'];
                                $overshoot -= $reduced * $it['harga_jual'];
                                $dikurangi = true;
                            }
                        }
                        unset($it);
                        $t['total'] = (int) array_sum(array_column($t['items'], 'subtotal'));
                    }
                }

                if ($overshoot > 0) {
                    // 3) drop nota paling belakang — hanya bila hasilnya masih >= batas bawah
                    // Drop bisa beberapa nota jika overshoot besar
                    while ($overshoot > 0 && count($transaksi) > 1) {
                        $lastTotal = end($transaksi)['total'];
                        $afterPop = $total - $lastTotal;
                        // Hitung total setelah pop
                        $totalAfterPop = $total - $lastTotal;
                        if ($totalAfterPop >= $batasBawah) {
                            array_pop($transaksi);
                            $total = $totalAfterPop;
                            $overshoot = $total - $batasAtas;
                            $dikurangi = true;
                        } else {
                            break;
                        }
                    }
                    if (!$dikurangi && count($transaksi) === 1) {
                        // nota tunggal: hapus item non-SN terakhir (pertahankan >= 1 item)
                        $t = &$transaksi[0];
                        if (count($t['items']) > 1) {
                            for ($i = count($t['items']) - 1; $i >= 0; $i--) {
                                if ($t['items'][$i]['no_sn'] === null) {
                                    array_splice($t['items'], $i, 1);
                                    $t['total'] = (int) array_sum(array_column($t['items'], 'subtotal'));
                                    $dikurangi = true;
                                    break;
                                }
                            }
                        }
                    }
                }

                if (! $dikurangi) {
                    break;
                }
            }
        }

        return $transaksi;
    }

    /**
     * Buat 1 nota tambahan ("top-up") dari barang yang masih punya sisa stok di periode.
     * Dipakai penyesuaian akhir ketika total draft masih di bawah toleransi target.
     */
    private function buatNotaTopUp(array &$sisaTotal, array &$avail, int $kebutuhan, int &$notaSeq, string $jamBuka, string $jamTutup, int $maksQtyPerNota): ?array
    {
        $best = null;
        foreach ($sisaTotal as $barangId => $sisa) {
            if ($sisa <= 0) {
                continue;
            }
            $b = Barang::find($barangId);
            if (! $b || $b->isSn()) {
                continue;
            }
            foreach ($avail[$barangId] ?? [] as $dayKey => $qty) {
                if ($qty <= 0) {
                    continue;
                }
                if ($best === null || $qty > $best['qty']) {
                    $best = [
                        'barang_id' => (int) $barangId,
                        'kode' => $b->kode_barang,
                        'nama' => $b->nama,
                        'dayKey' => $dayKey,
                        'qty' => (int) $qty,
                    ];
                }
            }
        }

        if ($best === null) {
            return null;
        }

        [$hargaJual, $hargaBeli] = $this->hargaBarang($best['barang_id'], Carbon::parse($best['dayKey'])->copy()->endOfDay());
        $price = max($hargaJual, $hargaBeli);
        $qty = (int) floor($kebutuhan / max(1, $price));
        $qty = max(1, min($qty, $best['qty'], $maksQtyPerNota));

        $day = Carbon::parse($best['dayKey']);
        [$hBuka] = array_map('intval', explode(':', $jamBuka));
        [$hTutup] = array_map('intval', explode(':', $jamTutup));
        $waktu = $day->copy()->setTime(rand($hBuka, max($hBuka, $hTutup - 1)), rand(0, 59));

        $notaSeq++;
        $refTopup = $this->refBebas($day->format('Ymd'), $notaSeq);
        $total = $qty * $price;

        // kurangi dari pool sisa
        $avail[$best['barang_id']][$best['dayKey']] = max(0, ($avail[$best['barang_id']][$best['dayKey']] ?? 0) - $qty);
        $sisaTotal[$best['barang_id']] = max(0, $sisaTotal[$best['barang_id']] - $qty);

        return [
            'ref' => $refTopup,
            'tanggal_waktu' => $waktu,
            'items' => [[
                'barang_id' => $best['barang_id'],
                'qty' => $qty,
                'harga_jual' => $price,
                'no_sn' => null,
                'subtotal' => $total,
            ]],
            'total' => $total,
        ];
    }

    /**
     * Generate REF_TRANSAKSI unik global: naikkan seq sampai tidak ada ref yang sama
     * (termasuk ref milik batch yang dibatalkan — tetap tersimpan utk audit).
     */
    private function refBebas(string $dayKey, int &$seq): string
    {
        do {
            $seq++;
            $ref = 'GEN-' . $dayKey . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        } while (TransaksiPenjualan::where('ref_transaksi', $ref)->exists());

        return $ref;
    }

    /**
     * Undo / batalkan batch yang SUDAH tersimpan (PRD 7 langkah 7-8; reversibel pasca-simpan).
     * Reversal atomik: stok dikembalikan (kartu keluar dihapus) & NO_SN dikembalikan ke 'tersedia'.
     * Baris transaksi & batch DI-PERTAHANKAN dgn status 'dibatalkan' (audit trail) dan dikeluarkan
     * dari laporan/omset/export karena seluruh query memakai status='tersimpan'.
     */
    public function batalBatchTersimpan(int $batchId): BatchGenerate
    {
        $batch = BatchGenerate::findOrFail($batchId);
        if ($batch->status !== 'tersimpan') {
            throw new InvalidArgumentException('Hanya batch berstatus tersimpan yang bisa dibatalkan (undo).');
        }

        // Terkunci bila ada nota yang sudah diedit (keputusan produk)
        if ($batch->transaksiPenjualan()->whereNotNull('diedit_at')->exists()) {
            throw new InvalidArgumentException(
                'Batch terkunci — ada nota telah diedit, sehingga undo tidak bisa dijalankan (konsistensi stok).'
            );
        }

        DB::transaction(function () use ($batch) {
            $transaksi = $batch->transaksiPenjualan()
                ->where('status', 'tersimpan')
                ->get();

            foreach ($transaksi as $trx) {
                // reversi NO_SN (kembalikan ke 'tersedia')
                foreach ($trx->items as $item) {
                    if ($item->no_sn) {
                        ReferensiSn::where('barang_id', $item->barang_id)
                            ->where('no_sn', $item->no_sn)
                            ->where('transaksi_penjualan_id', $trx->id)
                            ->update(['status' => 'tersedia', 'transaksi_penjualan_id' => null]);
                    }
                }
                // stok dikembalikan & kartu keluar dihapus
                $this->stock->batalEfekPenjualan($trx->id);
                // audit: baris tetap, status dipindah supaya tak terhitung di laporan
                $trx->update(['status' => 'dibatalkan']);
            }

            $batch->update(['status' => 'dibatalkan']);
        });

        return $batch->fresh();
    }

    /** Hapus draft periode tertentu (menimpa, aturan keras #3). */
    public function hapusDraftPeriode(string $mulai, string $selesai): void
    {
        // kolom date selalu disimpan Eloquent dengan format 'Y-m-d H:i:s' (SQLite),
        // jadi bandingkan dengan objek Carbon agar query match.
        $batches = BatchGenerate::where('status', 'draft')
            ->where('periode_mulai', Carbon::parse($mulai))
            ->where('periode_selesai', Carbon::parse($selesai))
            ->get();

        foreach ($batches as $b) {
            $this->hapusDraft($b->id);
        }
    }

    /** Batalkan draft (tanpa efek ke stok). */
    public function hapusDraft(int $batchId): void
    {
        DB::transaction(function () use ($batchId) {
            $batch = BatchGenerate::with('transaksiPenjualan')->findOrFail($batchId);
            if ($batch->status === 'tersimpan') {
                throw new InvalidArgumentException('Batch sudah tersimpan — tidak bisa dibatalkan. Batalkan lewat jalur hapus transaksi manual?');
            }
            foreach ($batch->transaksiPenjualan as $trx) {
                $trx->items()->delete();
                $trx->delete();
            }
            $batch->delete();
        });
    }

    /** Simpan draft → tersimpan (potong stok & tandai SN terjual). */
    public function simpanDraft(int $batchId): BatchGenerate
    {
        $batch = BatchGenerate::findOrFail($batchId);
        if ($batch->status !== 'draft') {
            throw new InvalidArgumentException('Hanya batch draft yang bisa disimpan.');
        }

        $this->penjualan->terapkanDraftRekonstruksi($batch);

        return $batch->fresh();
    }

    /** Edit satu baris item draft (review UI): qty/harga. */
    public function updateLine(TransaksiPenjualanItem $item, int $qty, int $hargaJual): void
    {
        if ($item->transaksiPenjualan->status !== 'draft') {
            throw new InvalidArgumentException('Baris sudah tersimpan, tidak bisa diedit lagi.');
        }
        if ($qty <= 0 || $hargaJual <= 0) {
            throw new InvalidArgumentException('Qty dan harga harus lebih dari 0.');
        }
        $barang = $item->barang;
        if ($barang->isSn() && ($qty !== 1 || $item->no_sn === null)) {
            throw new InvalidArgumentException('Barang ber-SN wajib qty = 1 dengan NO_SN.');
        }
        $item->qty = $qty;
        $item->harga_jual = $hargaJual;
        $item->subtotal = $qty * $hargaJual;
        $item->save();

        $trx = $item->transaksiPenjualan;
        $trx->total = (int) $trx->items()->sum('subtotal');
        $trx->save();

        $batch = $trx->batchGenerate;
        if ($batch) {
            $batch->total_generate = (int) $batch->transaksiPenjualan()->sum('total');
            $batch->selisih = $batch->total_generate - $batch->target_omset;
            $batch->save();
        }
    }

    /** Hapus satu nota draft pada review. */
    public function hapusNota(TransaksiPenjualan $trx): void
    {
        if ($trx->status !== 'draft') {
            throw new InvalidArgumentException('Hanya nota draft yang bisa dihapus.');
        }
        DB::transaction(function () use ($trx) {
            $batch = $trx->batchGenerate;
            $trx->items()->delete();
            $trx->delete();
            if ($batch) {
                $batch->total_generate = (int) $batch->transaksiPenjualan()->sum('total');
                $batch->selisih = $batch->total_generate - $batch->target_omset;
                $batch->save();
            }
        });
    }

    /** Hapus batch draft atau dibatalkan dari riwayat (bukan tersimpan). */
    public function hapusBatch(int $batchId): void
    {
        $batch = BatchGenerate::withCount('transaksiPenjualan')->findOrFail($batchId);

        if ($batch->status === 'tersimpan') {
            throw new InvalidArgumentException('Batch yang sudah tersimpan tidak bisa dihapus. Gunakan Undo terlebih dahulu.');
        }

        DB::transaction(function () use ($batch) {
            foreach ($batch->transaksiPenjualan as $trx) {
                $trx->items()->delete();
                $trx->delete();
            }
            $batch->delete();
        });
    }
}
