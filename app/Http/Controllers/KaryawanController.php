<?php

namespace App\Http\Controllers;

use App\Exceptions\ImportFileException;
use App\Models\Karyawan;
use App\Models\TokoSetting;
use App\Services\Pph21ImportService;
use App\Services\Pph21TerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class KaryawanController extends Controller
{
    public function __construct(private Pph21ImportService $impor) {}

    public function index(Request $request)
    {
        $query = Karyawan::query();
        if ($s = trim((string) $request->get('q'))) {
            $query->where(fn ($q) => $q->where('nama', 'like', "%{$s}%")->orWhere('nik', 'like', "%{$s}%"));
        }
        if ($request->get('aktif') === '0') {
            $query->where('is_aktif', false);
        } elseif ($request->get('aktif') === '1') {
            $query->where('is_aktif', true);
        }
        if (($cabang = trim((string) $request->get('cabang'))) !== '') {
            $query->where('cabang', $cabang);
        }
        $karyawans = $query->orderBy('nama')->paginate(15)->withQueryString();
        $daftarCabang = Karyawan::whereNotNull('cabang')->where('cabang', '<>', '')
            ->distinct()->orderBy('cabang')->pluck('cabang');

        return view('karyawan.index', compact('karyawans', 'daftarCabang'));
    }

    public function create()
    {
        return view('karyawan.form', ['karyawan' => null, 'ref' => $this->ref(), 'daftarCabang' => $this->daftarCabang()]);
    }

    public function store(Request $request)
    {
        $data = $this->validasi($request);
        $karyawan = Karyawan::create($data);

        return redirect()->route('karyawan.index')->with('success', 'Karyawan "'.$karyawan->nama.'" berhasil ditambahkan.');
    }

    public function edit(Karyawan $karyawan)
    {
        return view('karyawan.form', ['karyawan' => $karyawan, 'ref' => $this->ref(), 'daftarCabang' => $this->daftarCabang()]);
    }

    public function update(Request $request, Karyawan $karyawan)
    {
        $data = $this->validasi($request, $karyawan->id);
        $karyawan->update($data);

        return redirect()->route('karyawan.index')->with('success', 'Karyawan diperbarui.');
    }

    public function destroy(Karyawan $karyawan)
    {
        try {
            if ($karyawan->pph21Items()->exists()) {
                throw new InvalidArgumentException('Karyawan ini sudah punya data gaji PPh 21 — nonaktifkan saja (AKTIF = TIDAK).');
            }
            $karyawan->delete();

            return redirect()->route('karyawan.index')->with('success', 'Karyawan dihapus.');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function downloadTemplate()
    {
        return response()->download($this->impor->templateKaryawan(), 'template-karyawan.xlsx');
    }

    public function importTemplate(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:10240']]);

        try {
            $hasil = $this->impor->importKaryawan($request->file('file')->getRealPath());

            return redirect()->route('karyawan.index')->with('success', 'Import selesai: '.$hasil['dibuat'].' karyawan baru, '.$hasil['diupdate'].' karyawan diperbarui.');
        } catch (ImportFileException $e) {
            return back()->with('import_errors', [
                'judul' => 'Import karyawan gagal divalidasi',
                'ringkasan' => $e->getMessage(),
                'errors' => $e->getErrors(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Import excel karyawan gagal di luar validasi', [
                'file' => $request->file('file')?->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);

            return back()->with('import_errors', [
                'judul' => 'Gagal membaca file Excel',
                'ringkasan' => 'Terjadi kesalahan membaca file Excel — detail ada di storage/logs/laravel.log.',
                'errors' => [],
            ]);
        }
    }

    private function ref(): array
    {
        return [
            'status_pegawai' => Pph21TerService::STATUS_PEGAWAI,
            'ptkp' => Pph21TerService::PTKP,
            'sertifikat' => Pph21TerService::SERTIFIKAT,
            'kode_objek' => Pph21TerService::KODE_OBJEK,
        ];
    }

    /** Saran cabang: nilai tetap + yang sudah dipakai (teks bebas). */
    private function daftarCabang(): array
    {
        return collect(Karyawan::CABANG_SARAN)
            ->merge(Karyawan::whereNotNull('cabang')->where('cabang', '<>', '')->distinct()->orderBy('cabang')->pluck('cabang'))
            ->unique()->values()->all();
    }

    private function validasi(Request $request, ?int $kecualiId = null): array
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:100'],
            'nik' => ['required', 'regex:/^\d{16}$/', 'unique:karyawans,nik'.($kecualiId ? ','.$kecualiId : '')],
            'status_pegawai' => ['required', 'in:'.implode(',', Pph21TerService::STATUS_PEGAWAI)],
            'passport' => ['nullable', 'string', 'max:50'],
            'ptkp' => ['required', 'in:'.implode(',', Pph21TerService::PTKP)],
            'posisi' => ['required', 'string', 'max:60'],
            'cabang' => ['nullable', 'string', 'max:50'],
            'sertifikat' => ['required', 'in:'.implode(',', Pph21TerService::SERTIFIKAT)],
            'kode_objek' => ['required', 'in:'.implode(',', Pph21TerService::KODE_OBJEK)],
            'id_tku' => ['nullable', 'regex:/^\d{22}$/'],
            'link_ktp' => ['nullable', 'url', 'max:500'],
            'link_kk' => ['nullable', 'url', 'max:500'],
            'link_berkas_1' => ['nullable', 'url', 'max:500'],
            'link_berkas_2' => ['nullable', 'url', 'max:500'],
            'link_berkas_3' => ['nullable', 'url', 'max:500'],
            'is_aktif' => ['nullable', 'boolean'],
        ]);
        $data['is_aktif'] = (bool) ($request->get('is_aktif') ?? false);
        foreach (['passport', 'id_tku', 'cabang'] as $kosong) {
            if (($data[$kosong] ?? '') === '') {
                $data[$kosong] = null;
            }
        }
        if (! $data['id_tku']) {
            $npwp = preg_replace('/\D/', '', (string) (TokoSetting::utama()->npwp ?? ''));
            $data['id_tku'] = strlen($npwp) === 16 ? $npwp.'000000' : null;
        }

        return $data;
    }
}
