<?php

namespace App\Http\Controllers;

use App\Exceptions\ImportFileException;
use App\Exceptions\StokException;
use App\Http\Controllers\Concerns\MembatasiExportSebulan;
use App\Models\InvoicePembelian;
use App\Models\PembayaranHutang;
use App\Models\Supplier;
use App\Models\TokoSetting;
use App\Services\ExcelImportService;
use App\Services\PembelianService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

class PembelianController extends Controller
{
    use MembatasiExportSebulan;

    public function __construct(
        private PembelianService $service,
        private ExcelImportService $excel,
    ) {}

    /**
     * Form Transaksi Pembelian baru (PRD 5.1).
     */
    public function create()
    {
        $suppliers = Supplier::orderBy('nama')->get();
        $ppnPersen = (int) (TokoSetting::utama()->ppn_persen ?? 11);

        return view('pembelian.transaksi', [
            'invoice' => null,
            'suppliers' => $suppliers,
            'ppnPersen' => $ppnPersen,
            'ppnOtomatis' => true,
        ]);
    }

    public function store(Request $request)
    {
        try {
            $invoice = $this->service->simpan($this->parseForm($request), 'manual');

            return redirect()->route('pembelian.show', $invoice)
                ->with('success', 'Transaksi pembelian ' . $invoice->no_invoice . ' disimpan. Stok bertambah & histori harga tercatat.');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    /**
     * Edit invoice (PRD 5.5a) — operasi atomik.
     */
    public function edit(InvoicePembelian $invoice)
    {
        $suppliers = Supplier::orderBy('nama')->get();
        $invoice->loadMissing('items');
        $ppnPersen = (int) (TokoSetting::utama()->ppn_persen ?? 11);
        // Status auto awal: centang bila PPN tersimpan sama dengan hasil auto dari DPP saat ini.
        $dpp = (int) ($invoice->total_setelah_diskon ?? 0);
        $ppnOtomatis = (int) ($invoice->ppn ?? 0) === PembelianService::hitungPpnOtomatis($dpp, $ppnPersen);

        return view('pembelian.transaksi', compact('invoice', 'suppliers', 'ppnPersen', 'ppnOtomatis'));
    }

    public function update(Request $request, InvoicePembelian $invoice)
    {
        try {
            $this->service->edit($invoice, $this->parseForm($request, $invoice));

            return redirect()->route('pembelian.show', $invoice)->with('success', 'Invoice ' . $invoice->no_invoice . ' diperbarui (efek stok disesuaikan).');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    /**
     * Query daftar invoice 1 status + filter tab (periode/supplier/cabang/cari),
     * dipakai index (paginate) & export (get semua) agar IDENTIK.
     */
    private function queryInvoice(Request $request, string $status)
    {
        $query = InvoicePembelian::with('supplier')
            ->withSum('pembayaranHutang as cicilan_sum', 'nominal')
            ->where('status', $status);

        [$awal, $akhir] = $this->periodeDariRequest($request);
        if ($awal && $akhir) {
            $query->whereBetween('tanggal_transaksi', [$awal->copy()->startOfDay(), $akhir->copy()->endOfDay()]);
        }
        if ($sid = (int) $request->query('supplier')) {
            $query->where('supplier_id', $sid);
        }
        if (($cabang = trim((string) $request->get('cabang'))) !== '') {
            $query->where('cabang', $cabang);
        }

        if ($s = trim((string) $request->get('q'))) {
            $query->where(fn ($q) => $q->where('no_invoice', 'like', "%{$s}%")
                ->orWhereHas('supplier', fn ($sq) => $sq->where('nama', 'like', "%{$s}%")));
        }

        return $query;
    }

    /** Data Hutang Belum Lunas (PRD 5.2a) — dengan filter periode (bulan/tahun/rentang) + supplier. */
    public function invoiceHutang(Request $request)
    {
        $query = $this->queryInvoice($request, 'belum_lunas')
            ->withCount('pembayaranHutang as cicilan_count');

        [$awal, $akhir] = $this->periodeDariRequest($request);
        $invoices = $query->latest('tanggal_transaksi')->paginate((int) ($request->get('entries') ?: 10))->withQueryString();
        $suppliers = Supplier::orderBy('nama')->get(['id', 'nama']);
        $daftarCabang = InvoicePembelian::where('jenis', 'hutang')
            ->whereNotNull('cabang')->where('cabang', '<>', '')
            ->distinct()->orderBy('cabang')->pluck('cabang');
        [$labelBeli, $totalBeli, $sisaBeli, $countBeli] = $this->kartuPembelian($request, $awal, $akhir);

        return view('pembelian.invoice-hutang', compact('invoices', 'suppliers', 'daftarCabang', 'labelBeli', 'totalBeli', 'sisaBeli', 'countBeli'));
    }

    /** Data Pembelian (Lunas/Cash) (PRD 5.2b) — dengan filter periode + supplier. */
    public function invoiceLunas(Request $request)
    {
        $query = $this->queryInvoice($request, 'lunas');

        [$awal, $akhir] = $this->periodeDariRequest($request);
        $invoices = $query->latest('tanggal_transaksi')->paginate((int) ($request->get('entries') ?: 10))->withQueryString();
        $suppliers = Supplier::orderBy('nama')->get(['id', 'nama']);
        $daftarCabang = InvoicePembelian::whereNotNull('cabang')->where('cabang', '<>', '')
            ->distinct()->orderBy('cabang')->pluck('cabang');
        [$labelBeli, $totalBeli, $sisaBeli, $countBeli] = $this->kartuPembelian($request, $awal, $akhir);

        return view('pembelian.invoice-lunas', compact('invoices', 'suppliers', 'daftarCabang', 'labelBeli', 'totalBeli', 'sisaBeli', 'countBeli'));
    }

    /**
     * Export tab Hutang / Lunas ke Excel & PDF. DIBATASI 1 bulan
     * (trait MembatasiExportSebulan); angka = query tab yg sama.
     */
    public function exportHutangXlsx(Request $request)
    {
        return $this->unduhExport($request, 'belum_lunas', 'DATA HUTANG BELUM LUNAS', 'pembelian-hutang', 'xlsx');
    }

    public function exportHutangPdf(Request $request)
    {
        return $this->unduhExport($request, 'belum_lunas', 'DATA HUTANG BELUM LUNAS', 'pembelian-hutang', 'pdf');
    }

    public function exportLunasXlsx(Request $request)
    {
        return $this->unduhExport($request, 'lunas', 'DATA PEMBELIAN LUNAS/CASH', 'pembelian-lunas', 'xlsx');
    }

    public function exportLunasPdf(Request $request)
    {
        return $this->unduhExport($request, 'lunas', 'DATA PEMBELIAN LUNAS/CASH', 'pembelian-lunas', 'pdf');
    }

    private function unduhExport(Request $request, string $status, string $judul, string $prefix, string $format)
    {
        $request->validate([
            'bulan' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'supplier' => ['nullable', 'integer'],
            'cabang' => ['nullable', 'string', 'max:50'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            [$awal, $akhir, $label] = self::periodeExportSebulan($request);
            // Request dimurnikan (tahun dibuang) agar query tab selalu
            // memakai bulan/from-to yg sudah lolos batas 1 bulan.
            $bersih = new Request($request->only(['bulan', 'from', 'to', 'supplier', 'cabang', 'q']));
            $invoices = $this->queryInvoice($bersih, $status)->oldest('tanggal_transaksi')->get();
            $baris = $invoices->map(fn ($inv) => [
                'tanggal' => $inv->tanggal_transaksi->format('Y-m-d'),
                'no_invoice' => $inv->no_invoice,
                'supplier' => $inv->supplier->nama ?? '-',
                'jenis' => ucfirst((string) $inv->jenis),
                'cabang' => $inv->labelCabang(),
                'total' => (int) $inv->total_setelah_diskon + (int) $inv->ppn,
                'dp' => (int) $inv->bayar_atau_dp,
                'cicilan' => (int) ($inv->cicilan_sum ?? 0),
                'sisa' => (int) $inv->sisa_hutang,
                'status' => ucwords(str_replace('_', ' ', (string) $inv->status)),
                'jatuh_tempo' => $inv->jatuh_tempo ? $inv->jatuh_tempo->format('Y-m-d') : '-',
            ])->values();
            $ringkasan = [
                'Periode' => $awal->format('d M Y') . ' – ' . $akhir->format('d M Y'),
                'Jumlah' => $baris->count() . ' invoice',
                'Total' => (int) $baris->sum('total'),
                'Sisa' => (int) $baris->sum('sisa'),
            ];
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $nama = "{$prefix}-{$label}." . $format;
        $path = storage_path('app/private/export/' . pathinfo($nama, PATHINFO_FILENAME) . '-' . now()->format('Ymd-His') . '.' . $format);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        if ($format === 'xlsx') {
            $spreadsheet = new Spreadsheet();
            $this->isiSheetRingkasanBeli($spreadsheet->getActiveSheet(), $judul, $ringkasan);
            $this->isiSheetRincianBeli($spreadsheet->createSheet(), $baris);
            (new XlsxWriter($spreadsheet))->save($path);
            $spreadsheet->disconnectWorksheets();

            return response()->download($path, $nama, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

        $pdf = Pdf::loadView('pembelian.export-pdf', [
            'toko' => TokoSetting::utama(),
            'judul' => $judul,
            'ringkasan' => $ringkasan,
            'baris' => $baris,
        ])->setPaper('a4', 'landscape');
        file_put_contents($path, $pdf->output());

        // Header eksplisit agar Symfony melewati penebak MIME (hemat memori).
        return response()->download($path, $nama, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend(true);
    }

    private function isiSheetRingkasanBeli($sheet, string $judul, array $ringkasan): void
    {
        $sheet->setTitle('Ringkasan');
        $sheet->setCellValue('A1', $judul);
        $baris = 3;
        foreach ($ringkasan as $label => $nilai) {
            $sheet->setCellValue("A{$baris}", $label);
            $sheet->setCellValue("B{$baris}", $nilai);
            $baris++;
        }
        $sheet->getStyle('A3:A' . ($baris - 1))->getFont()->setBold(true);
        $sheet->getStyle('A3:B2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E6F4EA');
        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setAutoSize(true);
    }

    private function isiSheetRincianBeli($sheet, $baris): void
    {
        $sheet->setTitle('Rincian');
        $headers = ['Tanggal', 'No Invoice', 'Supplier', 'Jenis', 'Cabang', 'Total (incl PPN)', 'DP', 'Cicilan', 'Sisa', 'Status', 'Jatuh Tempo'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue([$i + 1, 1], $h);
        }
        $sheet->getStyle('A1:K1')->getFont()->setBold(true);
        $sheet->getStyle('A1:K1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E6F4EA');

        $no = 2;
        foreach ($baris as $b) {
            $sheet->fromArray([$b['tanggal'], $b['no_invoice'], $b['supplier'], $b['jenis'], $b['cabang'], $b['total'], $b['dp'], $b['cicilan'], $b['sisa'], $b['status'], $b['jatuh_tempo']], null, "A{$no}");
            $no++;
        }
        foreach (['F', 'G', 'H', 'I'] as $kol) {
            $sheet->getStyle("{$kol}2:{$kol}{$no}")->getNumberFormat()->setFormatCode('#,##0');
        }
        foreach (range('A', 'K') as $kol) {
            $sheet->getColumnDimension($kol)->setAutoSize(true);
        }
    }

    public function show(InvoicePembelian $invoice)
    {
        $invoice->load(['items.barang', 'supplier', 'pembayaranHutang']);

        return view('pembelian.show', compact('invoice'));
    }

    /** Pembayaran/lunasi hutang (PRD 5.2a). */
    public function bayarStore(Request $request, InvoicePembelian $invoice)
    {
        $data = $request->validate([
            'nominal' => ['required', 'integer', 'min:1'],
            'tanggal_bayar' => ['required', 'date'],
            'catatan' => ['nullable', 'string', 'max:255'],
            'cabang' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $this->service->pelunasan($invoice, (int) $data['nominal'], Carbon::parse($data['tanggal_bayar']), $data['catatan'] ?? null, $data['cabang'] ?? null);

            $pesan = $invoice->fresh()->sudahLunas()
                ? 'Pembayaran diterima. Invoice kini lunas dan berpindah ke Data Pembelian.'
                : 'Pembayaran diterima. Sisa hutang: ' . uang($invoice->fresh()->sisa_hutang) . '.';

            return back()->with('success', $pesan);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Hapus 1 pembayaran hutang (revert): sisa dikembalikan & status dihitung ulang. */
    public function bayarDestroy(PembayaranHutang $pembayaran)
    {
        $noInvoice = $pembayaran->invoicePembelian?->no_invoice ?? ('#' . $pembayaran->invoice_pembelian_id);
        $nominal = (int) $pembayaran->nominal;

        try {
            $inv = $this->service->hapusPembayaran($pembayaran);

            return back()->with('success', 'Pembayaran ' . uang($nominal) . ' pada ' . $noInvoice . ' dibatalkan. Sisa hutang kini ' . uang($inv->sisa_hutang) . '.');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Laman khusus bayar bulk: form + riwayat (tracking). */
    public function bayarBulkIndex()
    {
        $suppliers = Supplier::orderBy('nama')->get();
        $daftarCabang = InvoicePembelian::where('jenis', 'hutang')
            ->whereNotNull('cabang')->where('cabang', '<>', '')
            ->distinct()->orderBy('cabang')->pluck('cabang');

        $riwayat = \App\Models\BulkBayarHutang::with('supplier')
            ->withCount('pembayaran as baris_aktif')
            ->latest()
            ->paginate(10);

        return view('pembelian.bayar-bulk-index', compact('suppliers', 'daftarCabang', 'riwayat'));
    }

    /** Pratinjau alokasi bayar-bulk per supplier (read-only, tanpa menulis DB). */
    public function bayarBulkPreview(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:supplier,id'],
            'nominal' => ['required', 'integer', 'min:1'],
            'tanggal_bayar' => ['required', 'date'],
            'catatan' => ['nullable', 'string', 'max:255'],
            'bukti_url' => ['nullable', 'url', 'max:1000'],
            'cabang' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $supplier = Supplier::findOrFail((int) $data['supplier_id']);
            $cabang = normalisasi_teks($data['cabang'] ?? null);
            $hasil = $this->service->simulasiBulk((int) $data['supplier_id'], (int) $data['nominal'], $cabang);

            return view('pembelian.bayar-bulk-preview', [
                'supplier' => $supplier,
                'hasil' => $hasil,
                'nominal' => (int) $data['nominal'],
                'tanggalBayar' => $data['tanggal_bayar'],
                'catatan' => $data['catatan'] ?? null,
                'buktiUrl' => $data['bukti_url'] ?? null,
                'cabang' => $cabang,
            ]);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    /** Eksekusi bayar-bulk setelah pratinjau dikonfirmasi (atomik all-or-nothing). */
    public function bayarBulkConfirm(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:supplier,id'],
            'nominal' => ['required', 'integer', 'min:1'],
            'tanggal_bayar' => ['required', 'date'],
            'catatan' => ['nullable', 'string', 'max:255'],
            'bukti_url' => ['nullable', 'url', 'max:1000'],
            'cabang' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $hasil = $this->service->bayarBulk(
                (int) $data['supplier_id'],
                (int) $data['nominal'],
                Carbon::parse($data['tanggal_bayar']),
                $data['catatan'] ?? null,
                $data['bukti_url'] ?? null,
                normalisasi_teks($data['cabang'] ?? null)
            );

            $bagian = collect($hasil['rinci'])->map(fn ($r) => $r['invoice']->no_invoice
                . ($r['lunas_baru'] ? ' lunas' : ' (sisa ' . uang($r['sisa_baru']) . ')'))->implode('; ');
            $pesan = 'Bayar bulk ' . uang($hasil['dialokasi']) . ' dialokasikan ke ' . count($hasil['rinci']) . ' invoice: ' . $bagian . '.';
            if ($hasil['kembalian'] > 0) {
                $pesan .= ' Kembalian (tidak dicatat): ' . uang($hasil['kembalian']) . '.';
            }

            return redirect()->route('pembelian.bayar-bulk.index')->with('success', $pesan);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    /** Batalkan 1 grup bulk sekaligus (revert atomik). */
    public function bayarBulkCancel(string $grup)
    {
        try {
            $hasil = $this->service->batalkanBulk($grup);

            return back()->with('success', 'Bulk dibatalkan: ' . uang($hasil['nominal_kembali']) . ' dikembalikan ke ' . count($hasil['invoice_ids']) . ' invoice (' . $hasil['baris'] . ' baris pembayaran).');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Hapus invoice pembelian (PRD 5.5b) — dengan konfirmasi force bila stok minus. */
    public function destroy(Request $request, InvoicePembelian $invoice)
    {
        try {
            $this->service->hapus($invoice, $request->boolean('force'));
            $msg = $request->boolean('force')
                ? 'Invoice ' . $invoice->no_invoice . ' dihapus. Stok menjadi minus — cek Kartu Stok untuk investigasi.'
                : 'Invoice ' . $invoice->no_invoice . ' dihapus (efek stok dibatalkan).';

            return $request->expectsJson()
                ? response()->json(['success' => true, 'message' => $msg])
                : redirect()->route('pembelian.invoice-hutang')->with('success', $msg);
        } catch (StokException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => $e->getMessage(),
                    'stok_warning' => true,
                    'detail' => $e->getDetail(),
                ], 422);
            }

            return back()->with('stok_warning', [
                'invoice' => $invoice->id,
                'pesan' => $e->getMessage(),
                'detail' => $e->getDetail(),
            ]);
        } catch (InvalidArgumentException $e) {
            return $request->expectsJson()
                ? response()->json(['success' => false, 'error' => $e->getMessage()], 422)
                : back()->with('error', $e->getMessage());
        }
    }

    /** Bulk delete: hapus banyak invoice via AJAX, toleran error per item. */
    public function bulkDelete(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $deleted = [];
        $errors = [];
        foreach ($validated['ids'] as $id) {
            $inv = InvoicePembelian::find($id);
            if (! $inv) {
                $errors[$id] = 'Invoice tidak ditemukan.';
                continue;
            }
            try {
                $this->service->hapus($inv, false);
                $deleted[] = $id;
            } catch (Throwable $e) {
                $errors[$id] = $e->getMessage();
            }
        }

        return response()->json([
            'success' => count($deleted) > 0,
            'deleted' => $deleted,
            'errors' => $errors,
            'message' => count($deleted) . ' invoice dihapus.' . (count($errors) ? ' ' . count($errors) . ' gagal.' : ''),
        ]);
    }

    public function print(InvoicePembelian $invoice)
    {
        $invoice->load(['items.barang', 'supplier', 'pembayaranHutang']);

        return view('pembelian.print', compact('invoice'));
    }

    /** Bulk print: cetak banyak invoice terpilih (dari tab Hutang / Lunas) dalam satu halaman. */
    public function printBatch(Request $request)
    {
        // ids yang sudah tidak ada dibuang diam-diam oleh whereIn; hanya validasi tipe integer.
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $invoices = InvoicePembelian::with(['items.barang', 'supplier'])
            ->whereIn('id', $validated['ids'])
            ->orderBy('tanggal_transaksi')->orderBy('id')
            ->get();

        if ($invoices->isEmpty()) {
            return back()->with('error', 'Tidak ada invoice valid untuk dicetak.');
        }

        return view('pembelian.print-batch', compact('invoices'));
    }

    // ---- Excel: template & import pembelian (PRD 5.3) ----
    public function downloadTemplate()
    {
        $path = $this->excel->templatePembelian();

        return response()->download($path, 'template-pembelian.xlsx');
    }

    public function import(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:10240']]);

        try {
            $hasil = $this->excel->importPembelian($request->file('file')->getRealPath());

            return redirect()->route('pembelian.invoice-lunas')
                ->with('success', 'Import pembelian selesai: ' . $hasil['dibuat'] . ' invoice baru, ' . $hasil['diupdate'] . ' invoice diperbarui.');
        } catch (ImportFileException $e) {
            return back()->with('import_errors', [
                'judul' => 'Import pembelian gagal divalidasi',
                'ringkasan' => $e->getMessage(),
                'errors' => $e->getErrors(),
            ]);
        } catch (\Throwable $e) {
            // 500 putih dulu terjadi saat XlsxReader fatal di server yg ekstensi XML-reader belum aktif.
            // Tangkap & catat agar user melihat pesan ramah, bukan halaman kosong.
            Log::error('Import excel pembelian gagal di luar validasi', [
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
     * Resolve periode dari query string (bulan > tahun > rentang from/to).
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function periodeDariRequest(Request $request): array
    {
        if ($bulan = (string) $request->query('bulan')) {
            if (preg_match('/^\d{4}-\d{2}$/', $bulan) && Carbon::createFromFormat('Y-m', $bulan) !== false) {
                $awal = Carbon::createFromFormat('Y-m', $bulan)->startOfMonth();
                $akhir = $awal->copy()->endOfMonth();
                return [$awal, $akhir];
            }
        }
        if ($tahun = (int) $request->query('tahun')) {
            if ($tahun >= 2000 && $tahun <= 2099) {
                $awal = Carbon::create($tahun, 1, 1)->startOfDay();
                $akhir = Carbon::create($tahun, 12, 31)->endOfDay();
                return [$awal, $akhir];
            }
        }
        $from = $request->query('from');
        $to = $request->query('to');
        $parsedFrom = $from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? Carbon::parse($from) : null;
        $parsedTo = $to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? Carbon::parse($to) : null;
        if ($parsedFrom && $parsedTo && $parsedFrom->lte($parsedTo)) {
            return [$parsedFrom, $parsedTo];
        }

        return [null, null];
    }

    /**
     * Kartu Pembelian: mengikuti filter periode + supplier milik tabel
     * (tanpa search/pagination). Tanpa filter = semua waktu.
     * @param array{0: ?Carbon, 1: ?Carbon} $periode hasil periodeDariRequest
     * @return array{0: string, 1: int, 2: int, 3: int} [label, total, sisa, count]
     */
    private function kartuPembelian(Request $request, ?Carbon $awal, ?Carbon $akhir): array
    {
        $query = InvoicePembelian::query();
        $label = 'Semua Waktu';
        if ($awal && $akhir) {
            $query->whereBetween('tanggal_transaksi', [$awal->copy()->startOfDay(), $akhir->copy()->endOfDay()]);
            $label = $this->labelPeriodeBeli($request, $awal, $akhir);
        }
        if ($sid = (int) $request->query('supplier')) {
            $query->where('supplier_id', $sid);
            if ($nama = Supplier::whereKey($sid)->value('nama')) {
                $label .= ' · ' . $nama;
            }
        }

        return [
            $label,
            (int) (clone $query)->sum('total_setelah_diskon') + (int) (clone $query)->sum('ppn'),
            (int) (clone $query)->sum('sisa_hutang'),
            (int) (clone $query)->count(),
        ];
    }

    /** Label periode kartu mengikuti aturan filter tabel (bulan > tahun > rentang). */
    private function labelPeriodeBeli(Request $request, Carbon $awal, Carbon $akhir): string
    {
        if (($bulan = (string) $request->query('bulan')) && preg_match('/^\d{4}-\d{2}$/', $bulan)) {
            return bulan_id($awal);
        }
        if (($tahun = (int) $request->query('tahun')) && $tahun >= 2000 && $tahun <= 2099) {
            return 'Tahun ' . $tahun;
        }

        return $awal->format('d/m/Y') . ' – ' . $akhir->format('d/m/Y');
    }

    private function parseForm(Request $request, ?InvoicePembelian $invoice = null): array
    {
        $validated = $request->validate([
            'no_invoice' => ['nullable', 'string', 'max:40'],
            'supplier_id' => ['required', 'integer', 'exists:supplier,id'],
            'tanggal_transaksi' => ['required', 'date'],
            'jenis' => ['required', 'in:cash,hutang'],
            'diskon_total' => ['nullable', 'integer', 'min:0'],
            'ppn' => ['nullable', 'integer', 'min:0'],
            'ppn_otomatis' => ['nullable'],
            'bayar_atau_dp' => ['nullable', 'integer', 'min:0'],
            'jatuh_tempo' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.barang_id' => ['required', 'integer', 'exists:barang,id'],
            'items.*.harga_beli' => ['required', 'integer', 'min:1'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.diskon_item' => ['nullable', 'integer', 'min:0'],
            'items.*.no_sn' => ['nullable', 'string', 'max:255'],
        ]);

        $ppnOtomatis = $request->boolean('ppn_otomatis');

        return [
            'no_invoice' => $validated['no_invoice'] ?? null,
            'supplier_id' => $validated['supplier_id'],
            'tanggal_transaksi' => Carbon::parse($validated['tanggal_transaksi'])->format('Y-m-d H:i'),
            'jenis' => $validated['jenis'],
            'diskon_total' => (int) ($validated['diskon_total'] ?? 0),
            // Auto (checkbox centang) → null agar service menghitung dari DPP + tarif.
            // Manual (tidak centang) → integer eksplisit (0 = tanpa PPN).
            'ppn' => $ppnOtomatis ? null : (int) ($validated['ppn'] ?? 0),
            'ppn_otomatis' => $ppnOtomatis,
            'bayar_atau_dp' => (int) ($validated['bayar_atau_dp'] ?? 0),
            'jatuh_tempo' => $validated['jatuh_tempo'] ?? null,
            'items' => array_map(fn ($it) => [
                'barang_id' => (int) $it['barang_id'],
                'harga_beli' => (int) $it['harga_beli'],
                'qty' => (int) $it['qty'],
                'diskon_item' => (int) ($it['diskon_item'] ?? 0),
                'no_sn' => $it['no_sn'] ?? null,
            ], $validated['items']),
        ];
    }
}
