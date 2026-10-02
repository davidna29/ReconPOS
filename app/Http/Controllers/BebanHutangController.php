<?php

namespace App\Http\Controllers;

use App\Exceptions\ImportFileException;
use App\Models\InvoicePembelian;
use App\Models\PembayaranHutang;
use App\Models\Supplier;
use App\Models\TokoSetting;
use App\Services\ExcelImportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

class BebanHutangController extends Controller
{
    public function __construct(private ExcelImportService $excel) {}

    public function index(Request $request)
    {
        [$cards, $grand, $belumDibebani, $belumDibebaniTotal, $daftarCabang, $rinciSemua, $komposisi] = self::rekap($request);
        $suppliers = Supplier::orderBy('nama')->get();

        return view('beban-hutang.index', compact('cards', 'grand', 'belumDibebani', 'belumDibebaniTotal', 'daftarCabang', 'suppliers', 'rinciSemua', 'komposisi'));
    }

    /**
     * Rekap beban hutang bersama utk admin & publik (lihat Publik\BebanHutangController).
     * Hanya invoice jenis=hutang. Filter periode memakai tanggal_transaksi.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: array, 2: \Illuminate\Contracts\Pagination\LengthAwarePaginator, 3: int, 4: \Illuminate\Support\Collection, 5: bool, 6: \Illuminate\Support\Collection}
     */
    public static function rekap(Request $request): array
    {
        [$awal, $akhir] = self::rentangDariRequest($request);

        $query = InvoicePembelian::with('supplier')->where('jenis', 'hutang');
        if ($awal && $akhir) {
            $query->whereBetween('tanggal_transaksi', [$awal->copy()->startOfDay(), $akhir->copy()->endOfDay()]);
        }
        if (($cabang = trim((string) $request->get('cabang'))) !== '') {
            $query->where('cabang', $cabang);
        }
        if (($supplierId = (int) $request->get('supplier')) > 0) {
            $query->where('supplier_id', $supplierId);
        }
        if ($s = trim((string) $request->get('q'))) {
            $query->where(fn ($q) => $q->where('no_invoice', 'like', "%{$s}%"));
        }

        $invoices = $query->orderBy('tanggal_transaksi')->get();

        // Makna kohort + kini: filter memilih FAKTUR-nya (via tanggal_transaksi);
        // semua angka uang = kondisi terkini. Dibayar = uang muka + SELURUH cicilan
        // kapan pun (tanpa batas tanggal_bayar) sehingga Hutang = Sisa + Dibayar.
        // Dihitung dari himpunan faktur terfilter yg sama (bukan query terpisah).
        // Atribusi cicilan memakai SNAPSHOT cabang baris bayar (bukan cabang faktur
        // terkini) agar riwayat tak bergeser bila cabang faktur diubah kemudian.
        // ATURAN PORTABEL (MariaDB strict ONLY_FULL_GROUP_BY menolak GROUP BY
        // ekspresi mentah walau identik dgn SELECT — pernah 500 di hosting):
        // agregasi cabang WAJIB via derived table + GROUP BY kolom polos,
        // atau agregasi di PHP. Jangan kembalikan ke groupByRaw ekspresi.
        $cabangSnapshot = "COALESCE(NULLIF(pembayaran_hutang.cabang, ''), NULLIF(invoice_pembelian.cabang, ''), 'Belum Dibebani')";
        $perBayar = DB::table('pembayaran_hutang')
            ->join('invoice_pembelian', 'invoice_pembelian.id', '=', 'pembayaran_hutang.invoice_pembelian_id')
            ->whereIn('pembayaran_hutang.invoice_pembelian_id', $invoices->pluck('id'))
            ->selectRaw("{$cabangSnapshot} as cabang, pembayaran_hutang.nominal as nominal");
        $cicilanPerCabang = DB::query()->fromSub($perBayar, 'bayar_per_cabang')
            ->selectRaw('cabang, SUM(nominal) as total')
            ->groupBy('cabang')
            ->pluck('total', 'cabang');

        // Urutan kartu: TB1 → TB2 → TB3 → abjad → 'Belum Dibebani' paling bawah.
        // Hutang = total tagihan TERMASUK PPN (sebanding dgn sisa = hutang − terbayar).
        $tagihan = fn ($items) => (int) $items->sum('total_setelah_diskon') + (int) $items->sum('ppn');
        $urutan = array_flip(InvoicePembelian::CABANG_SARAN);
        $cards = $invoices->groupBy(fn ($inv) => $inv->labelCabang())
            ->map(function ($items, $nama) use ($tagihan, $cicilanPerCabang) {
                $dibayar = (int) $items->sum('bayar_atau_dp') + (int) ($cicilanPerCabang[$nama] ?? 0);

                return [
                    'cabang' => $nama,
                    'lembar' => $items->count(),
                    'hutang' => $tagihan($items),
                    'sisa' => (int) $items->sum('sisa_hutang'),
                    'dibayar' => $dibayar,
                    'lunas' => $items->where('status', 'lunas')->count(),
                    'belum_lunas' => $items->where('status', '!=', 'lunas')->count(),
                    'items' => $items->values(),
                ];
            });
        // Pembayaran yg snapshot cabangnya tak punya faktur dalam scope
        // (mis. cabang faktur diubah sesudah dibayar) tetap dapat kartu induk.
        foreach ($cicilanPerCabang as $nama => $total) {
            if (! $cards->has($nama)) {
                $cards->put($nama, [
                    'cabang' => $nama,
                    'lembar' => 0,
                    'hutang' => 0,
                    'sisa' => 0,
                    'dibayar' => (int) $total,
                    'lunas' => 0,
                    'belum_lunas' => 0,
                    'items' => collect(),
                ]);
            }
        }
        $cards = $cards
            ->sortBy(fn ($c) => $c['cabang'] === 'Belum Dibebani' ? '~~~' : sprintf('%03d-%s', $urutan[$c['cabang']] ?? 999, $c['cabang']))
            ->values();

        $grand = [
            'lembar' => $invoices->count(),
            'hutang' => $tagihan($invoices),
            'sisa' => (int) $invoices->sum('sisa_hutang'),
            'dibayar' => (int) $invoices->sum('bayar_atau_dp') + (int) $cicilanPerCabang->sum(),
            'lunas' => $invoices->where('status', 'lunas')->count(),
        ];

        // Daftar Tetapkan: paginasi 10/halaman (massal via template Excel).
        $belumDibebani = InvoicePembelian::with('supplier')
            ->where('jenis', 'hutang')
            ->whereNull('cabang')
            ->latest('tanggal_transaksi')
            ->paginate(10, ['*'], 'halaman_belum');
        $belumDibebaniTotal = (int) $belumDibebani->total();

        $daftarCabang = InvoicePembelian::where('jenis', 'hutang')
            ->whereNotNull('cabang')->where('cabang', '<>', '')
            ->distinct()->orderBy('cabang')->pluck('cabang');

        // Rincian tiap kartu dibatasi 10 baris kecuali ?rinci_semua=1.
        $rinciSemua = $request->boolean('rinci_semua');

        // Komposisi gabungan: porsi nominal hutang tiap cabang berbeban
        // (Belum Dibebani tidak ikut 100%). Warna tetap per cabang.
        $warnaTetap = [
            'TERMINAL BAN 1' => 'bg-primary',
            'TERMINAL BAN 2' => 'bg-special-teal',
            'TERMINAL BAN 3' => 'bg-hutang-amber',
        ];
        $paletCadangan = ['bg-cash-blue', 'bg-lunas-green', 'bg-danger-red'];
        $berbeban = $cards->filter(fn ($c) => $c['cabang'] !== 'Belum Dibebani')->values();
        $totalBerbeban = (int) $berbeban->sum('hutang');
        $komposisi = $berbeban->map(function ($c, $i) use ($totalBerbeban, $warnaTetap, $paletCadangan) {
            return [
                'cabang' => $c['cabang'],
                'hutang' => $c['hutang'],
                'lembar' => $c['lembar'],
                'porsi' => $totalBerbeban > 0 ? (int) round($c['hutang'] / $totalBerbeban * 100) : 0,
                'warna' => $warnaTetap[$c['cabang']] ?? $paletCadangan[$i % count($paletCadangan)],
            ];
        });

        return [$cards, $grand, $belumDibebani, $belumDibebaniTotal, $daftarCabang, $rinciSemua, $komposisi];
    }

    /**
     * Tetapkan/ubah/kosongkan beban cabang utk 1..N faktur hutang
     * (label saja — pembayaran tak tersentuh). Cabang kosong = kembali Belum Dibebani.
     */
    public function tetapkan(Request $request)
    {
        $data = $request->validate([
            'cabang' => ['nullable', 'string', 'max:50'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:invoice_pembelian,id'],
        ]);

        $cabang = normalisasi_teks($data['cabang'] ?? null);

        $bukanHutang = InvoicePembelian::whereIn('id', $data['ids'])->where('jenis', '!=', 'hutang')->count();
        if ($bukanHutang > 0) {
            return back()->with('error', 'Hanya invoice hutang yg bisa dibebani.')->withInput();
        }

        $jumlah = 0;
        DB::transaction(function () use ($data, $cabang, &$jumlah) {
            $jumlah = InvoicePembelian::whereIn('id', $data['ids'])->update(['cabang' => $cabang]);
        });

        return $cabang === null
            ? back()->with('success', 'Beban ' . $jumlah . ' faktur dikosongkan (kembali Belum Dibebani).')
            : back()->with('success', 'Beban "' . $cabang . '" ditetapkan ke ' . $jumlah . ' faktur.');
    }

    public function downloadTemplate()
    {
        $path = $this->excel->templateBebanHutang();

        return response()->download($path, 'template-beban-hutang.xlsx');
    }

    /**
     * Export rekap + rincian beban hutang ke Excel (2 sheet).
     * DIBATASI 1 bulan: mode bulan OK; from/to maks 31 hari;
     * mode tahun & tanpa filter DITOLAK (data bisa raksasa).
     */
    public function exportXlsx(Request $request)
    {
        $request->validate([
            'bulan' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        try {
            [$awal, $akhir, $label] = self::periodeExport($request);
            [$cards, $grand, $baris] = $this->dataExport($request);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $spreadsheet = new Spreadsheet();
        $this->isiSheetRingkasan($spreadsheet->getActiveSheet(), $cards, $grand, $awal, $akhir);
        $this->isiSheetRincian($spreadsheet->createSheet(), $baris);

        $nama = "beban-hutang-{$label}.xlsx";
        $path = storage_path('app/private/export/' . pathinfo($nama, PATHINFO_FILENAME) . '-' . now()->format('Ymd-His') . '.xlsx');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return response()->download($path, $nama, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Export rekap + rincian beban hutang ke PDF (A4 landscape).
     * Batas 1 bulan sama dgn exportXlsx.
     */
    public function exportPdf(Request $request)
    {
        $request->validate([
            'bulan' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        try {
            [$awal, $akhir, $label] = self::periodeExport($request);
            [$cards, $grand, $baris] = $this->dataExport($request);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $pdf = Pdf::loadView('beban-hutang.export-pdf', [
            'toko' => TokoSetting::utama(),
            'cards' => $cards,
            'grand' => $grand,
            'baris' => $baris,
            'periode' => $awal->format('d M Y') . ' – ' . $akhir->format('d M Y'),
        ])->setPaper('a4', 'landscape');

        $nama = "beban-hutang-{$label}.pdf";
        $path = storage_path('app/private/export/' . pathinfo($nama, PATHINFO_FILENAME) . '-' . now()->format('Ymd-His') . '.pdf');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $pdf->output());

        // Header eksplisit agar Symfony melewati penebak MIME (hemat memori).
        return response()->download($path, $nama, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Resolve periode export (maks 1 bulan).
     *
     * @return array{0: Carbon, 1: Carbon, 2: string}
     *
     * @throws InvalidArgumentException
     */
    private static function periodeExport(Request $request): array
    {
        $bulan = (string) $request->get('bulan');
        if ($bulan !== '' && preg_match('/^\d{4}-\d{2}$/', $bulan)) {
            $awal = Carbon::createFromFormat('Y-m', $bulan)->startOfMonth();

            return [$awal, $awal->copy()->endOfMonth(), $bulan];
        }

        $from = (string) $request->get('from');
        $to = (string) $request->get('to');
        if ($from !== '' && $to !== '') {
            $awal = Carbon::parse($from)->startOfDay();
            $akhir = Carbon::parse($to)->endOfDay();
            if ($awal->greaterThan($akhir)) {
                throw new InvalidArgumentException('Rentang tanggal tidak valid (Dari melewati Sampai).');
            }
            if ($awal->diffInDays($akhir) > 30) {
                throw new InvalidArgumentException('Export dibatasi 1 bulan — rentang Dari–Sampai maksimal 31 hari.');
            }

            return [$awal, $akhir, $awal->format('Ymd') . '-' . $akhir->format('Ymd')];
        }

        throw new InvalidArgumentException('Export dibatasi 1 bulan — pilih Bulan atau isi rentang Dari–Sampai (maksimal 31 hari). Mode Tahun tidak didukung.');
    }

    /**
     * Data export: pakai ulang rekap() agar angka IDENTIK dgn layar
     * (kohort+kini, snapshot cabang, hutang termasuk PPN).
     * Request dimurnikan (tahun dibuang) agar filter periode rekap
     * selalu memakai bulan/from-to yg sudah lolos periodeExport.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: array, 2: \Illuminate\Support\Collection}
     */
    private function dataExport(Request $request): array
    {
        $bersih = new Request($request->only(['bulan', 'from', 'to', 'cabang', 'supplier', 'q']));
        [$cards, $grand] = self::rekap($bersih);

        $semuaId = $cards->flatMap(fn ($c) => $c['items']->pluck('id'))->unique()->values();
        $cicilanPerFaktur = $semuaId->isEmpty() ? collect() : PembayaranHutang::query()
            ->whereIn('invoice_pembelian_id', $semuaId)
            ->selectRaw('invoice_pembelian_id, SUM(nominal) as total')
            ->groupBy('invoice_pembelian_id')
            ->pluck('total', 'invoice_pembelian_id');

        $baris = $cards->flatMap(function ($c) use ($cicilanPerFaktur) {
            return $c['items']->map(function ($inv) use ($cicilanPerFaktur) {
                $hutang = (int) $inv->total_setelah_diskon + (int) $inv->ppn;
                $cicilan = (int) ($cicilanPerFaktur[$inv->id] ?? 0);

                return [
                    'tanggal' => $inv->tanggal_transaksi->format('Y-m-d'),
                    'no_invoice' => $inv->no_invoice,
                    'supplier' => $inv->supplier->nama ?? '-',
                    'cabang' => $inv->labelCabang(),
                    'hutang' => $hutang,
                    'dp' => (int) $inv->bayar_atau_dp,
                    'cicilan' => $cicilan,
                    'sisa' => (int) $inv->sisa_hutang,
                    'status' => ucwords(str_replace('_', ' ', (string) $inv->status)),
                ];
            });
        })->values();

        return [$cards, $grand, $baris];
    }

    private function isiSheetRingkasan($sheet, $cards, array $grand, Carbon $awal, Carbon $akhir): void
    {
        $sheet->setTitle('Ringkasan');
        $sheet->setCellValue('A1', 'REKAP BEBAN HUTANG PER CABANG');
        $sheet->setCellValue('A2', 'Periode: ' . $awal->format('d M Y') . ' – ' . $akhir->format('d M Y') . ' (filter memilih faktur by tgl transaksi; Dibayar = DP + seluruh cicilan kini)');
        $headers = ['Cabang', 'Lembar', 'Hutang (incl PPN)', 'Dibayar', 'Sisa', 'Lunas', 'Belum Lunas'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue([$i + 1, 4], $h);
        }
        $sheet->getStyle('A4:G4')->getFont()->setBold(true);
        $sheet->getStyle('A4:G4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E6F4EA');

        $baris = 5;
        foreach ($cards as $c) {
            $sheet->fromArray([$c['cabang'], $c['lembar'], $c['hutang'], $c['dibayar'], $c['sisa'], $c['lunas'], $c['belum_lunas']], null, "A{$baris}");
            $baris++;
        }
        $sheet->fromArray(['GRAND TOTAL', $grand['lembar'], $grand['hutang'], $grand['dibayar'], $grand['sisa'], $grand['lunas'], ''], null, "A{$baris}");
        $sheet->getStyle("A{$baris}:G{$baris}")->getFont()->setBold(true);
        foreach (['C', 'D', 'E'] as $kol) {
            $sheet->getStyle("{$kol}5:{$kol}{$baris}")->getNumberFormat()->setFormatCode('#,##0');
        }
        foreach (range('A', 'G') as $kol) {
            $sheet->getColumnDimension($kol)->setAutoSize(true);
        }
    }

    private function isiSheetRincian($sheet, $baris): void
    {
        $sheet->setTitle('Rincian');
        $headers = ['Tanggal', 'No Invoice', 'Supplier', 'Cabang', 'Hutang (incl PPN)', 'DP', 'Cicilan', 'Sisa', 'Status'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue([$i + 1, 1], $h);
        }
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);
        $sheet->getStyle('A1:I1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E6F4EA');

        $no = 2;
        foreach ($baris as $b) {
            $sheet->fromArray([$b['tanggal'], $b['no_invoice'], $b['supplier'], $b['cabang'], $b['hutang'], $b['dp'], $b['cicilan'], $b['sisa'], $b['status']], null, "A{$no}");
            $no++;
        }
        foreach (['E', 'F', 'G', 'H'] as $kol) {
            $sheet->getStyle("{$kol}2:{$kol}{$no}")->getNumberFormat()->setFormatCode('#,##0');
        }
        foreach (range('A', 'I') as $kol) {
            $sheet->getColumnDimension($kol)->setAutoSize(true);
        }
    }

    public function importTemplate(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:10240']]);

        try {
            $hasil = $this->excel->importBebanHutang($request->file('file')->getRealPath());

            return redirect()->route('beban-hutang.index')->with('success', 'Pemilahan selesai: ' . $hasil['diperbarui'] . ' faktur diberi beban cabang.');
        } catch (ImportFileException $e) {
            return back()->with('import_errors', [
                'judul' => 'Import beban hutang gagal divalidasi',
                'ringkasan' => $e->getMessage(),
                'errors' => $e->getErrors(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Import excel beban hutang gagal di luar validasi', [
                'file' => $request->file('file')?->getClientOriginalName(),
                'ukuran' => $request->file('file')?->getSize(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $pesan = 'Terjadi kesalahan membaca file Excel: ' . $e->getMessage() . ' — detail lengkap ada di storage/logs/laravel.log.';

            return back()->with('import_errors', [
                'judul' => 'Gagal membaca file Excel',
                'ringkasan' => $pesan,
                'errors' => [$pesan],
            ]);
        }
    }

    /**
     * Rentang periode dari query string (prioritas: bulan > tahun > from/to),
     * pola sama dgn filter kartu omset dashboard & penjualan.
     *
     * @return array{0: ?\Illuminate\Support\Carbon, 1: ?\Illuminate\Support\Carbon}
     */
    private static function rentangDariRequest(Request $request): array
    {
        if (($bulan = (string) $request->get('bulan')) !== '' && preg_match('/^\d{4}-\d{2}$/', $bulan)) {
            $awal = \Illuminate\Support\Carbon::createFromFormat('Y-m', $bulan)->startOfMonth();

            return [$awal, $awal->copy()->endOfMonth()];
        }
        if (($tahun = (int) $request->get('tahun')) >= 2000 && $tahun <= 2099) {
            return [\Illuminate\Support\Carbon::create($tahun, 1, 1), \Illuminate\Support\Carbon::create($tahun, 12, 31)];
        }
        $from = (string) $request->get('from');
        $to = (string) $request->get('to');
        if ($from !== '' && $to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $a = \Illuminate\Support\Carbon::parse($from);
            $b = \Illuminate\Support\Carbon::parse($to);
            if ($a->lte($b)) {
                return [$a, $b];
            }
        }

        return [null, null];
    }
}
