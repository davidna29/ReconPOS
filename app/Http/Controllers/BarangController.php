<?php

namespace App\Http\Controllers;

use App\Exceptions\ImportFileException;
use App\Models\Barang;
use App\Models\KategoriBarang;
use App\Models\TokoSetting;
use App\Services\ExcelImportService;
use App\Services\HargaService;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class BarangController extends Controller
{
    public function __construct(
        private ExcelImportService $excel,
        private HargaService $harga,
        private StockService $stock,
    ) {}

    public function index(Request $request)
    {
        $query = Barang::with('kategori')->where('tipe_item', 'barang');

        if ($s = trim((string) $request->get('q'))) {
            $query->where(fn ($q) => $q->where('kode_barang', 'like', "%{$s}%")
                ->orWhere('nama', 'like', "%{$s}%")
                ->orWhere('merk', 'like', "%{$s}%")
                ->orWhere('varian', 'like', "%{$s}%")
                ->orWhere('ukuran_ban', 'like', "%{$s}%"));
        }
        if ($kategori = (int) $request->get('kategori')) {
            $query->where('kategori_id', $kategori);
        }
        if (($merk = trim((string) $request->get('merk'))) !== '') {
            $query->where('merk', $merk);
        }

        $barangs = $query->orderBy('kode_barang')->paginate((int) ($request->get('entries') ?: 10))->withQueryString();
        $kategoris = KategoriBarang::orderBy('nama')->get();
        $merks = Barang::where('tipe_item', 'barang')
            ->whereNotNull('merk')->where('merk', '<>', '')
            ->distinct()->orderBy('merk')->pluck('merk');

        return view('barang.index', compact('barangs', 'kategoris', 'merks'));
    }

    public function create()
    {
        $kategoris = KategoriBarang::orderBy('nama')->get();
        $settings = TokoSetting::utama();

        return view('barang.form', ['barang' => null, 'kategoris' => $kategoris, 'settings' => $settings]
            + self::daftarTeksBebas());
    }

    public function store(Request $request)
    {
        $data = $this->validasi($request);
        $kategoriId = $this->resolusiKategori($data['kategori'] ?? '');

        if ($data['mode_harga_jual'] === 'manual_terkunci' && ($data['harga_jual'] ?? 0) <= 0) {
            return back()->withErrors(['harga_jual' => 'Harga jual wajib diisi pada mode Kunci Manual.'])->withInput();
        }

        // Cegah duplikat walau beda huruf besar/kecil (mis. "Stok1" vs "stok1")
        if (Barang::whereRaw('UPPER(kode_barang) = ?', [strtoupper(trim($data['kode_barang']))])->exists()) {
            return back()->withErrors(['kode_barang' => 'Kode barang sudah ada (huruf besar/kecil tidak dibedakan).'])->withInput();
        }

        $barang = Barang::create([
            'tipe_item' => 'barang',
            'kode_barang' => $data['kode_barang'],
            'nama' => $data['nama'],
            'deskripsi' => $data['deskripsi'] ?? null,
            'kategori_id' => $kategoriId,
            'merk' => self::normalisasiTeks($data['merk'] ?? null),
            'varian' => self::normalisasiTeks($data['varian'] ?? null),
            'ukuran_ban' => self::normalisasiTeks($data['ukuran_ban'] ?? null),
            'satuan' => $data['satuan'] ?? 'pcs',
            'sn_atau_non_sn' => $data['sn_atau_non_sn'],
            'margin_override' => ($data['margin_override'] ?? null) !== '' && ($data['margin_override'] ?? null) !== null ? (int) $data['margin_override'] : null,
            'mode_harga_jual' => $data['mode_harga_jual'],
            'harga_jual' => (int) ($data['harga_jual'] ?? 0),
            'lokasi_rak' => $data['lokasi_rak'] ?? null,
            'status' => $data['status'],
        ]);

        // Stok Awal (PRD 4.3b jalur 1)
        if (($data['stock_awal'] ?? 0) > 0) {
            try {
                $this->stock->stokAwal($barang, (int) $data['stock_awal'], 'Stok awal barang baru');
            } catch (InvalidArgumentException $e) {
                return redirect()->route('barang.index')->with('error', 'Barang dibuat, tapi ' . $e->getMessage());
            }
        }

        return redirect()->route('barang.index')->with('success', 'Barang "' . $barang->nama . '" berhasil ditambahkan.');
    }

    public function show(Barang $barang)
    {
        $histori = $barang->historiHarga()->latest('tanggal_berlaku')->limit(25)->get();
        $kartu = $barang->kartuStok()->get();

        return view('barang.show', compact('barang', 'histori', 'kartu'));
    }

    public function edit(Barang $barang)
    {
        $kategoris = KategoriBarang::orderBy('nama')->get();
        $settings = TokoSetting::utama();
        $terkunci = $barang->sudahDipakaiTransaksi();

        return view('barang.form', compact('barang', 'kategoris', 'settings', 'terkunci')
            + self::daftarTeksBebas());
    }

    public function update(Request $request, Barang $barang)
    {
        $data = $this->validasi($request, $barang);
        $kategoriId = $this->resolusiKategori($data['kategori'] ?? '');

        if ($data['mode_harga_jual'] === 'manual_terkunci' && ($data['harga_jual'] ?? 0) <= 0) {
            return back()->withErrors(['harga_jual' => 'Harga jual wajib diisi pada mode Kunci Manual.'])->withInput();
        }

        $barang->update([
            'nama' => $data['nama'],
            'deskripsi' => $data['deskripsi'] ?? null,
            'kategori_id' => $kategoriId,
            'merk' => self::normalisasiTeks($data['merk'] ?? null),
            'varian' => self::normalisasiTeks($data['varian'] ?? null),
            'ukuran_ban' => self::normalisasiTeks($data['ukuran_ban'] ?? null),
            'satuan' => $data['satuan'] ?? 'pcs',
            'lokasi_rak' => $data['lokasi_rak'] ?? null,
            'status' => $data['status'],
            'margin_override' => ($data['margin_override'] ?? null) !== '' && ($data['margin_override'] ?? null) !== null ? (int) $data['margin_override'] : null,
            'mode_harga_jual' => $data['mode_harga_jual'],
        ]);

        // Toggle mode (PRD 4.1c): pindah ke otomatis → hitung ulang seketika
        if ($data['mode_harga_jual'] === 'otomatis') {
            $this->harga->toggleKeOtomatis($barang);
        } else {
            $barang->harga_jual = (int) $data['harga_jual'];
            $barang->save();
        }

        return redirect()->route('barang.index')->with('success', 'Barang "' . $barang->nama . '" diperbarui.');
    }

    public function destroy(Barang $barang)
    {
        try {
            if ($barang->sudahDipakaiTransaksi()) {
                throw new InvalidArgumentException('Barang sudah dipakai di transaksi pembelian/penjualan — tidak bisa dihapus demi integritas histori.');
            }
            $barang->delete();

            return redirect()->route('barang.index')->with('success', 'Barang dihapus.');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function kartuStok(Barang $barang)
    {
        $mutasi = $barang->kartuStok()->with(['invoicePembelian', 'transaksiPenjualan'])->get();
        $saldo = 0;
        $rows = $mutasi->map(function ($m) use (&$saldo) {
            $saldo += $m->qty;

            return [
                'tanggal' => $m->tanggal,
                'jenis' => $m->jenis,
                'qty' => $m->qty,
                'keterangan' => $m->keterangan,
                'referensi' => $m->invoicePembelian?->no_invoice ?? $m->transaksiPenjualan?->ref_transaksi ?? null,
                'saldo' => $saldo,
            ];
        });

        return view('barang.kartu-stok', compact('barang', 'rows'));
    }

    public function opnameStore(Request $request, Barang $barang)
    {
        $data = $request->validate([
            'stok_fisik' => ['required', 'integer', 'min:0'],
            'alasan' => ['required', 'string', 'min:3'],
        ]);

        try {
            $this->stock->opname($barang, (int) $data['stok_fisik'], $data['alasan']);

            return redirect()->route('barang.kartuStok', $barang)->with('success', 'Opname dicatat: stok fisik ' . $data['stok_fisik'] . ' (selisih tercatat di Kartu Stok).');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function stokAwalStore(Request $request, Barang $barang)
    {
        if ($barang->stock !== 0) {
            return back()->with('error', 'Stok awal hanya bisa diisi saat stok masih 0.');
        }
        $data = $request->validate([
            'qty' => ['required', 'integer', 'min:1'],
        ]);
        if ($barang->sudahDipakaiTransaksi()) {
            return back()->with('error', 'Barang sudah dipakai transaksi — gunakan jalur Opname.');
        }
        $this->stock->stokAwal($barang, (int) $data['qty'], 'Stok awal');

        return redirect()->route('barang.kartuStok', $barang)->with('success', 'Stok awal ' . $data['qty'] . ' tercatat.');
    }

    // ---- Excel: template & import master barang (PRD 4.3c) ----
    public function downloadTemplate()
    {
        $path = $this->excel->templateBarang();

        return response()->download($path, 'template-barang.xlsx');
    }

    public function importTemplate(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:10240']]);

        try {
            $hasil = $this->excel->importBarang($request->file('file')->getRealPath());

            return redirect()->route('barang.index')->with('success', 'Import selesai: ' . $hasil['dibuat'] . ' barang baru, ' . $hasil['diupdate'] . ' barang diperbarui.');
        } catch (ImportFileException $e) {
            return back()->with('import_errors', [
                'judul' => 'Import barang gagal divalidasi',
                'ringkasan' => $e->getMessage(),
                'errors' => $e->getErrors(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Import excel barang gagal di luar validasi', [
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

    // ---- Excel: update harga massal (PRD 4.3a) ----
    public function downloadTemplateHarga()
    {
        $path = $this->excel->templateUpdateHarga();

        return response()->download($path, 'template-update-harga.xlsx');
    }

    public function importUpdateHarga(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:10240']]);

        try {
            $hasil = $this->excel->importUpdateHarga($request->file('file')->getRealPath());

            return redirect()->route('barang.index')
                ->with('success', 'Update harga selesai: ' . $hasil['diupdate'] . ' diupdate, ' . $hasil['diabaikan'] . ' diabaikan (mode otomatis), ' . $hasil['tidak_ditemukan'] . ' kode tidak ditemukan.');
        } catch (ImportFileException $e) {
            return back()->with('import_errors', [
                'judul' => 'Update harga gagal divalidasi',
                'ringkasan' => $e->getMessage(),
                'errors' => $e->getErrors(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Update harga excel gagal di luar validasi', [
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

    public function cetakBarcode(Barang $barang)
    {
        return view('barang.barcode', compact('barang'));
    }

    /** Endpoint JSON pencarian barang utk form pembelian/penjualan (barcode/kode/nama). */
    public function cari(Request $request)
    {
        $s = trim((string) $request->get('q'));
        $query = Barang::with('kategori')->where('tipe_item', 'barang')
            ->whereIn('status', ['Dijual', 'Dijual & Untuk Servis']);
        if ($s !== '') {
            $query->where(fn ($q) => $q->where('kode_barang', 'like', "%{$s}%")->orWhere('nama', 'like', "%{$s}%"));
        }

        return response()->json($query->orderBy('kode_barang')->limit(20)->get([
            'id', 'kode_barang', 'nama', 'satuan', 'harga_jual', 'stock', 'sn_atau_non_sn',
        ]));
    }

    private function validasi(Request $request, ?Barang $barang = null): array
    {
        $rules = [
            'nama' => ['required', 'string', 'max:190'],
            'deskripsi' => ['nullable', 'string'],
            'kategori' => ['nullable', 'string', 'max:100'],
            'merk' => ['nullable', 'string', 'max:100'],
            'varian' => ['nullable', 'string', 'max:100'],
            'ukuran_ban' => ['nullable', 'string', 'max:50'],
            'satuan' => ['nullable', 'string', 'max:20'],
            'sn_atau_non_sn' => ['required', 'in:SN,NON_SN'],
            'margin_override' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'mode_harga_jual' => ['required', 'in:otomatis,manual_terkunci'],
            'harga_jual' => ['nullable', 'integer', 'min:0'],
            'lokasi_rak' => ['nullable', 'string', 'max:80'],
            'status' => ['required', 'in:Dijual,Untuk Servis,Dijual & Untuk Servis'],
        ];

        if ($barang) {
            $rules['kode_barang'] = ['sometimes'];
            // SN terkunci setelah ada transaksi pertama (PRD 4.3b)
            if ($barang->sudahDipakaiTransaksi()) {
                $rules['sn_atau_non_sn'] = ['required', 'in:' . $barang->sn_atau_non_sn];
            }
        } else {
            $rules['kode_barang'] = ['required', 'string', 'max:50', 'unique:barang,kode_barang'];
            $rules['stock_awal'] = ['nullable', 'integer', 'min:0'];
        }

        return $request->validate($rules);
    }

    private function resolusiKategori(string $nama): ?int
    {
        $nama = trim($nama);
        if ($nama === '') {
            return null;
        }
        // kategori baru otomatis dibuat bila belum ada (PRD 4.3c)
        return KategoriBarang::firstOrCreate(['nama' => $nama])->id;
    }

    /** Daftar nilai distinct merk/varian/ukuran utk datalist form (hindari typo). */
    public static function daftarTeksBebas(): array
    {
        $distinct = fn (string $kolom) => Barang::where('tipe_item', 'barang')
            ->whereNotNull($kolom)->where($kolom, '<>', '')
            ->distinct()->orderBy($kolom)->pluck($kolom);

        return [
            'merks' => $distinct('merk'),
            'varians' => $distinct('varian'),
            'ukurans' => $distinct('ukuran_ban'),
        ];
    }
    /** Normalisasi teks bebas merk/varian/ukuran (delegasi ke helper global). */
    public static function normalisasiTeks(?string $nilai): ?string
    {
        return normalisasi_teks($nilai);
    }}
