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
            ->whereBetween('tanggal_waktu', [$mulai->startOfDay(), $selesai->endOfDay()])
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
        [$timeline, $kapasitas, $closedDays] = $this->bangunTimeline(
            $mulai->copy()->startOfDay(),
            $selesai->copy()->endOfDay(),
            (array) ($params['excluded_item_ids'] ?? []),
        );

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
     * Bangun ketersediaan per hari + kapasitas maksimum.
     *
     * @return array{0: array, 1: int, 2: array} [timeline, kapasitas, closedDays]
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
        $kapasitas = 0;
        $closedDays = [];

        $settings = TokoSetting::utama();
        $libur = $settings->hari_libur ?? ['dates' => [], 'weekly' => []];

        foreach ($barangs as $barang) {
            $ev = $events->get($barang->id, collect());
            $balance = 0;
            $masukPerHari = []; // qty masuk per hari
            $penjualanHari = []; // qty keluar per hari (nilai positif)

            foreach ($ev as $row) {
                $tgl = Carbon::parse($row->tanggal);
                if ($row->qty > 0) {
                    $masukPerHari[$tgl->toDateString()] = ($masukPerHari[$tgl->toDateString()] ?? 0) + $row->qty;
                } else {
                    $penjualanHari[$tgl->toDateString()] = ($penjualanHari[$tgl->toDateString()] ?? 0) + abs($row->qty);
                }
            }

            $peakAvail = 0;
            foreach ($days as $key => $day) {
                // ketersediaan di awal hari = saldo sebelumnya - penjualan hari tsb (konservatif)
                $timeline[$barang->id][$key] = $balance - ($penjualanHari[$key] ?? 0);
                $balance += ($masukPerHari[$key] ?? 0) - ($penjualanHari[$key] ?? 0);
                if ($timeline[$barang->id][$key] > $peakAvail) {
                    $peakAvail = $timeline[$barang->id][$key];
                }
            }
            // kapasitas periode = total unit TERJUAL-maksimum (peak avail) x harga efektif
            if ($peakAvail > 0) {
                $kapasitas += $peakAvail * $this->harga->hargaJualEfektifPada($barang, $selesai->copy());
            }
        }

        // hari libur/tutup toko
        foreach ($days as $key => $day) {
            if ($this->isHariLibur($day, $libur)) {
                $closedDays[$key] = true;
            }
        }

        return [$timeline, $kapasitas, $closedDays];
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

        // bobot harian dari jumlah stok masuk (PRD 6 poin 4)
        $weights = [];
        $totalWeight = 0;
        foreach ($days as $key => $day) {
            if (isset($closedDays[$key])) {
                continue;
            }
            $w = (int) KartuStok::whereIn('barang_id', array_keys($timeline))
                ->where('tanggal', '>=', $day->copy()->startOfDay())
                ->where('tanggal', '<=', $day->copy()->endOfDay())
                ->where('qty', '>', 0)
                ->sum('qty');
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
            $b = Barang::find($barangId);
            if ($b && $b->isSn()) {
                $snPool[$barangId] = ReferensiSn::where('barang_id', $barangId)
                    ->where('status', 'tersedia')
                    ->orderBy('id')
                    ->pluck('no_sn')
                    ->all();
            }
        }

        $transaksi = [];
        $notaSeq = 0;
        $sisa = max(0, $target);
        $openDays = array_keys($weights);

        // Ketersediaan per hari = timeline hari itu - konsumsi simulasi s.d. hari itu (running balance)
        $simSold = [];              // total unit barang terjual simulasi (seluruh hari s.d. sekarang)
        $soldToday = [];            // soldToday[barang][day] — kuota hari itu yg sudah dipakai simulasi

        // isi global: jual unit hingga sisa tercapai / stok habis
        $sales = []; // [dayKey][] = ['barang_id', 'qty', 'price', 'no_sn']
        $guard = 0;
        while ($sisa > 0 && $guard++ < 20000) {
            // barang dengan unit tersisa
            $candidates = [];
            foreach ($unitSisa as $barangId => $u) {
                if ($u > 0 && ! empty($daysItem[$barangId])) {
                    $candidates[] = (int) $barangId;
                }
            }
            if (count($candidates) === 0) {
                break;
            }

            // pilih barang: dahulukan yang masih "terjangkau" sisa target,
            // di akhir (sisa < semua harga) ambil barang termurah agar overshoot minimal.
            $byid = [];
            foreach ($candidates as $cand) {
                $byid[$cand] = Barang::find($cand);
            }
            $affordable = [];
            $cheapest = null;
            $cheapestPrice = PHP_INT_MAX;
            foreach ($byid as $cand => $bobj) {
                if (! $bobj) {
                    continue;
                }
                $peek = (int) $this->hargaV($cand, $days[end($daysItem[$cand])] ?? $days[array_key_first($days)]);
                if ($peek <= $sisa) {
                    $affordable[] = $cand;
                }
                if ($peek < $cheapestPrice) {
                    $cheapestPrice = $peek;
                    $cheapest = $cand;
                }
            }
            foreach ($byid as $cand => $bobj) {
                // SN berpeluang terpilih HANYA bila harganya terjangkau sisa target
                // (atau mode semaksimal stok) — hindari 1 barang SN meledakkan target kecil.
                if ($bobj && $bobj->isSn() && count($snPool[$cand] ?? []) > 0) {
                    $peek = (int) $this->hargaV($cand, $days[end($daysItem[$cand])] ?? $days[array_key_first($days)]);
                    if ($peek <= $sisa || $kapasitasKurang) {
                        $affordable[] = $cand;
                    }
                }
            }
            $pickFrom = count($affordable) > 0 ? array_values(array_unique($affordable)) : ($cheapest !== null ? [$cheapest] : []);
            if (count($pickFrom) === 0) {
                $unitSisa[$candidates[array_rand($candidates)]] = 0;
                continue;
            }
            $totalU = 0;
            $uMap = [];
            foreach ($pickFrom as $cand) {
                $uMap[$cand] = $unitSisa[$cand];
                $totalU += $unitSisa[$cand];
            }
            $roll = rand(1, max(1, $totalU));
            $bid = null;
            foreach ($pickFrom as $cand) {
                $roll -= $uMap[$cand];
                if ($roll <= 0) {
                    $bid = $cand;
                    break;
                }
            }
            $bid ??= $pickFrom[array_rand($pickFrom)];

            // pilih hari valid utk barang tsb (bobot = bobot stok masuk hari itu)
            $validDays = array_values(array_intersect($daysItem[$bid], $openDays));
            if (count($validDays) === 0) {
                $unitSisa[$bid] = 0;
                continue;
            }
            $dayWeights = [];
            foreach ($validDays as $dk) {
                $dayWeights[$dk] = max(1, $weights[$dk]);
            }
            $totalDw = array_sum($dayWeights);
            $rollD = rand(1, $totalDw);
            $dayKey = null;
            foreach ($dayWeights as $dk => $dw) {
                $rollD -= $dw;
                if ($rollD <= 0) {
                    $dayKey = $dk;
                    break;
                }
            }
            $dayKey ??= $validDays[0];

            $b = Barang::find($bid);
            if (! $b) {
                $unitSisa[$bid] = 0;
                continue;
            }

            [$hargaJual, $hargaBeli] = $this->hargaBarang($bid, $days[$dayKey]);
            $price = $this->variasiHarga($hargaJual, $hargaBeli, $variasiMin, $variasiMax);

            // qty untuk mengisi sisa (paling akhir 1 unit — adjust harga menyempurnakan)
            // cap ganda: unit tersisa periode (peak) DAN sisa hari itu (running balance simulasi)
            $hariBatas = ($timeline[$bid][$dayKey] ?? 0) - ($simSold[$bid] ?? 0) - ($soldToday[$bid][$dayKey] ?? 0);
            $qty = 1;
            if ($sisa >= $price) {
                $qty = (int) floor($sisa / $price);
            }
            // cap qty per entri penjualan per item — dipecah lintas nota saat packaging (PRD realisme)
            $qty = min($qty, $unitSisa[$bid], max(0, $hariBatas), $maksQtyPerNota);
            if ($qty <= 0) {
                // hari itu sudah habis kuota simulasi — lewati barang ini utk hari tsb
                $soldToday[$bid][$dayKey] = PHP_INT_MAX;
                $unitSisa[$bid] = $unitSisa[$bid] > 0 ? -1 : $unitSisa[$bid]; // blokir pilihan hari itu
                continue;
            }
            $qty = max(1, $qty);

            // barang ber-SN: qty wajib 1 + NO_SN unik
            $noSn = null;
            if ($b->isSn()) {
                if (count($snPool[$bid] ?? []) === 0) {
                    $unitSisa[$bid] = 0;
                    continue;
                }
                $qty = 1;
                $noSn = array_pop($snPool[$bid]);
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
            $sisa -= $qty * $price;
        }

        // Paketkan penjualan per hari menjadi nota:
        // - tiap nota 1-5 item, BEDA barang (tidak ada 2 baris barang sama dalam 1 nota)
        // - qty per item per nota dibatasi maks_qty_item_per_nota (default 3)
        [$hBuka] = array_map('intval', explode(':', $jamBuka));
        [$hTutup] = array_map('intval', explode(':', $jamTutup));
        $maxItemsPerNota = 5;

        foreach ($sales as $dayKey => $daySales) {
            $day = $days[$dayKey];

            // greedy: tempatkan tiap entri ke nota pertama yang belum memuat barang tsb & masih ada slot
            $notas = [];
            foreach ($daySales as $c) {
                $qty = max(1, min((int) $c['qty'], $maksQtyPerNota));
                $entri = [
                    'barang_id' => $c['barang_id'],
                    'qty' => $qty,
                    'price' => $c['price'],
                    'no_sn' => $c['no_sn'],
                ];
                $idx = null;
                for ($i = 0; $i < count($notas); $i++) {
                    if (count($notas[$i]['items']) < $maxItemsPerNota && ! isset($notas[$i]['barang'][$c['barang_id']])) {
                        $idx = $i;
                        break;
                    }
                }
                if ($idx === null) {
                    $notas[] = ['items' => [$entri], 'barang' => [$c['barang_id'] => true]];
                } else {
                    $notas[$idx]['items'][] = $entri;
                    $notas[$idx]['barang'][$c['barang_id']] = true;
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

    private function hargaBarang(int $barangId, ?Carbon $day = null): array
    {
        static $cache = [];
        $key = $barangId . '|' . ($day ? $day->toDateString() : '');
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $barang = Barang::find($barangId);
        if (! $barang) {
            return [0, 0];
        }
        $res = $day ? $this->harga->hargaEfektifPada($barang, $day->copy()->endOfDay()) : [(int) $barang->harga_jual, (int) $barang->harga_beli_terakhir];
        $cache[$key] = $res;

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
        }

        $batasBawah = (int) floor($target * (1 - $toleransi / 100));
        $batasAtas = (int) ceil($target * (1 + $toleransi / 100));

        for ($iter = 0; $iter < 120; $iter++) {
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
                            $availQty = $avail[$it['barang_id']][$dayKey] ?? 0;
                            $sisa = $sisaTotal[$it['barang_id']] ?? 0;
                            if ($sisa <= 0) {
                                continue;
                            }
                            $tambah = min(max(1, (int) floor($kebutuhan / max(1, $it['harga_jual']))), max($availQty, $sisa), 50, $sisa);
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
                    // 3) nota top-up di hari yang masih punya stok tersisa
                    $topUp = $this->buatNotaTopUp($sisaTotal, $avail, $kebutuhan, $notaSeq, $jamBuka, $jamTutup, $maksQtyPerNota);
                    if ($topUp) {
                        $transaksi[] = $topUp;
                        $diproses = true;
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
                    if (count($transaksi) > 1) {
                        $afterPop = $total - end($transaksi)['total'];
                        if ($afterPop >= $batasBawah) {
                            array_pop($transaksi);
                            $dikurangi = true;
                        }
                    } else {
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
