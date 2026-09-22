<?php

namespace App\Http\Controllers;

use App\Models\TemplateExportPosConfig;
use App\Models\TokoSetting;
use App\Services\ExcelImportService;
use App\Services\ExportPosService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PengaturanController extends Controller
{
    public function index()
    {
        $settings = TokoSetting::utama();
        $mapping = TemplateExportPosConfig::aktif($settings->template_version);
        $barangs = \App\Models\Barang::where('tipe_item', 'barang')->orderBy('kode_barang')->get();

        // Diagnosa lingkungan utk upload/import Excel (read-only, tanpa dependensi baru):
        // di shared hosting ekstensi web PHP-FPM bisa beda dgn CLI — export jalan tapi import 500.
        $excelEnv = [
            'ekstensi' => app(ExcelImportService::class)->cekEkstensiExcel(),
            'ini' => [
                'PHP Versi' => PHP_VERSION,
                'upload_max_filesize' => ini_get('upload_max_filesize') ?: '-',
                'post_max_size' => ini_get('post_max_size') ?: '-',
                'memory_limit' => ini_get('memory_limit') ?: '-',
                'max_execution_time' => ini_get('max_execution_time') ?: '-',
            ],
        ];

        return view('pengaturan.index', compact('settings', 'mapping', 'barangs', 'excelEnv'));
    }

    /** Simpan seluruh seksi pengaturan toko (PRD 4.5). */
    public function update(Request $request)
    {
        $data = $request->validate([
            'nama_toko' => ['nullable', 'string', 'max:190'],
            'alamat' => ['nullable', 'string'],
            'telepon' => ['nullable', 'string', 'max:40'],
            'npwp' => ['nullable', 'string', 'max:30'],
            'margin_default' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'ppn_persen' => ['nullable', 'integer', 'min:0', 'max:100'],
            'jam_buka' => ['nullable', 'date_format:H:i'],
            'jam_tutup' => ['nullable', 'date_format:H:i'],
            'ambang_stok_menipis' => ['nullable', 'integer', 'min:0'],
            // parameter generate
            'nota_min' => ['nullable', 'integer', 'min:1', 'max:100'],
            'nota_max' => ['nullable', 'integer', 'min:1', 'max:100'],
            'toleransi_persen' => ['nullable', 'integer', 'min:0', 'max:20'],
            'variasi_min' => ['nullable', 'integer', 'min:-50', 'max:0'],
            'variasi_max' => ['nullable', 'integer', 'min:-50', 'max:50'],
            'maks_qty_item_per_nota' => ['nullable', 'integer', 'min:1', 'max:20'],
            'maks_sku_per_nota' => ['nullable', 'integer', 'min:1', 'max:5'],
            'excluded_item_ids' => ['nullable', 'array'],
            'excluded_item_ids.*' => ['integer', 'exists:barang,id'],
            // hari libur
            'libur_dates' => ['nullable', 'array'],
            'libur_dates.*' => ['nullable', 'date'],
            'libur_weekly' => ['nullable', 'array'],
            'libur_weekly.*' => ['in:Mon,Tue,Wed,Thu,Fri,Sat,Sun'],
            'template_version' => ['nullable', 'string', 'max:20'],
        ]);

        if ((int) ($data['nota_min'] ?? 1) > (int) ($data['nota_max'] ?? 8)) {
            return back()->with('error', 'Jumlah nota min tidak boleh lebih besar dari nota max.')->withInput();
        }

        $settings = TokoSetting::utama();
        $settings->update([
            'nama_toko' => $data['nama_toko'] ?? $settings->nama_toko,
            'alamat' => $data['alamat'] ?? $settings->alamat,
            'telepon' => $data['telepon'] ?? $settings->telepon,
            'npwp' => trim($data['npwp'] ?? $settings->npwp),
            'margin_default' => (int) ($data['margin_default'] ?? $settings->margin_default),
            'ppn_persen' => (int) ($data['ppn_persen'] ?? $settings->ppn_persen ?? 11),
            'jam_buka' => $data['jam_buka'] ?? $settings->jam_buka,
            'jam_tutup' => $data['jam_tutup'] ?? $settings->jam_tutup,
            'ambang_stok_menipis' => (int) ($data['ambang_stok_menipis'] ?? $settings->ambang_stok_menipis),
            'hari_libur' => [
                'dates' => $data['libur_dates'] ?? ($settings->hari_libur['dates'] ?? []),
                'weekly' => $data['libur_weekly'] ?? ($settings->hari_libur['weekly'] ?? []),
            ],
            'parameter_generate' => array_merge($settings->parameterGenerate(), [
                'nota_min' => (int) ($data['nota_min'] ?? $settings->parameterGenerate()['nota_min']),
                'nota_max' => (int) ($data['nota_max'] ?? $settings->parameterGenerate()['nota_max']),
                'toleransi_persen' => (int) ($data['toleransi_persen'] ?? $settings->parameterGenerate()['toleransi_persen']),
                'variasi_min' => (int) ($data['variasi_min'] ?? $settings->parameterGenerate()['variasi_min']),
                'variasi_max' => (int) ($data['variasi_max'] ?? $settings->parameterGenerate()['variasi_max']),
                'maks_qty_item_per_nota' => (int) ($data['maks_qty_item_per_nota'] ?? $settings->parameterGenerate()['maks_qty_item_per_nota']),
                'maks_sku_per_nota' => (int) ($data['maks_sku_per_nota'] ?? $settings->parameterGenerate()['maks_sku_per_nota']),
                'excluded_item_ids' => array_map('intval', $data['excluded_item_ids'] ?? []),
            ]),
            'template_version' => $data['template_version'] ?? $settings->template_version,
        ]);

        return redirect()->route('pengaturan.index')->with('success', 'Pengaturan toko berhasil disimpan.');
    }

    /** Terapkan margin default yang sedang aktif ke semua SKU mode otomatis (tanpa override). */
    public function terapkanMargin()
    {
        $settings = TokoSetting::utama();
        $margin = (int) $settings->margin_default;
        $count = app(\App\Services\HargaService::class)->terapkanMarginDefaultKeSemua($margin);

        return redirect()->route('pengaturan.index')
            ->with('success', "Margin {$margin}% diterapkan ke {$count} SKU otomatis (histori harga lama tetap).");
    }

    /** Ubah password admin (keamanan akun — 1 akun tanpa registrasi publik). */
    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'password_lama' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();
        if (! Hash::check($validated['password_lama'], $user->password)) {
            return back()->with('error', 'Password lama tidak sesuai.');
        }
        if (Hash::check($validated['password'], $user->password)) {
            return back()->with('error', 'Password baru tidak boleh sama dengan password lama.');
        }

        // cast 'hashed' pada User otomatis menghash password baru
        $user->update(['password' => $validated['password']]);

        return back()->with('success', 'Password admin berhasil diubah. Gunakan password baru saat login berikutnya.');
    }

    /** Simpan mapping kolom Template Export POS (PRD 4.2, konfigurabel bukan hardcode). */
    public function updateTemplate(Request $request)
    {
        $validated = $request->validate([
            'template_version' => ['required', 'string', 'max:20'],
            'kolom' => ['required', 'array', 'min:1'],
            'kolom.*.nama_kolom' => ['required', 'string', 'max:40'],
            'kolom.*.urutan' => ['required', 'integer', 'min:1'],
            'kolom.*.wajib_opsional' => ['required', 'in:wajib,opsional'],
            'kolom.*.format' => ['nullable', 'string', 'max:40'],
            'kolom.*.nilai_default' => ['nullable', 'string', 'max:40'],
            'kolom.*.sumber_data' => ['nullable', 'string', 'max:80'],
        ]);

        DB::transaction(function () use ($validated) {
            TemplateExportPosConfig::where('template_version', $validated['template_version'])->delete();
            foreach ($validated['kolom'] as $k) {
                TemplateExportPosConfig::create([
                    'template_version' => $validated['template_version'],
                    'nama_kolom' => $k['nama_kolom'],
                    'urutan' => (int) $k['urutan'],
                    'wajib_opsional' => $k['wajib_opsional'],
                    'format' => $k['format'] ?? null,
                    'nilai_default' => $k['nilai_default'] ?? null,
                    'sumber_data' => $k['sumber_data'] ?? null,
                ]);
            }
            TokoSetting::utama()->update(['template_version' => $validated['template_version']]);
        });

        return redirect()->route('pengaturan.index')->with('success', 'Template Export POS diperbarui (versi ' . $validated['template_version'] . ').');
    }

    /** Reset mapping ke default v1 (PRD 8b). */
    public function resetTemplate()
    {
        $settings = TokoSetting::utama();
        $versi = $settings->template_version;

        DB::transaction(function () use ($versi) {
            TemplateExportPosConfig::where('template_version', $versi)->delete();
            foreach (ExportPosService::mappingDefault() as $m) {
                TemplateExportPosConfig::create($m + ['template_version' => $versi]);
            }
        });

        return redirect()->route('pengaturan.index')->with('success', 'Template export di-reset ke mapping default v1.');
    }

    /** Atur PIN Mode Publik opsional (di Pengaturan — form TERPISAH). */
    public function updatePublikPin(Request $request)
    {
        $data = $request->validate([
            'publik_pin_enabled' => ['nullable', 'boolean'],
            'publik_pin' => ['nullable', 'string', 'min:4', 'max:20'],
            'publik_pin_confirmation' => ['nullable', 'string', 'min:4', 'max:20'],
            'publik_pin_hapus' => ['nullable', 'boolean'],
        ]);

        $enabled = (bool) ($data['publik_pin_enabled'] ?? false);
        $pin = trim((string) ($data['publik_pin'] ?? ''));
        $pinConfirmation = trim((string) ($data['publik_pin_confirmation'] ?? ''));
        $hapus = (bool) ($data['publik_pin_hapus'] ?? false);

        $settings = TokoSetting::utama();

        // Hapus PIN
        if ($hapus) {
            $settings->update([
                'publik_pin_enabled' => false,
                'publik_pin_hash' => null,
            ]);

            return redirect()->route('pengaturan.index')->with('success', 'PIN Mode Publik dihapus — akses publik kembali terbuka tanpa PIN.');
        }

        // Aktifkan butuh PIN baru
        if ($enabled) {
            // Jika belum ada hash dan tidak ada PIN baru → tolak
            if (empty($settings->publik_pin_hash) && $pin === '') {
                return back()->with('error', 'Aktifkan PIN butuh PIN baru (4-20 karakter). Isi PIN + konfirmasi.')->withInput();
            }

            // Jika ada PIN baru, validasi konfirmasi
            if ($pin !== '') {
                if ($pin !== $pinConfirmation) {
                    return back()->with('error', 'Konfirmasi PIN tidak cocok.')->withInput();
                }
                $settings->update([
                    'publik_pin_enabled' => true,
                    'publik_pin_hash' => Hash::make($pin),
                ]);

                return redirect()->route('pengaturan.index')->with('success', 'PIN Mode Publik diaktifkan/diubah — tamu wajib memasukkan PIN untuk buka /publik.');
            }

            // PIN tidak diubah, hanya aktifkan (hash sudah ada)
            $settings->update(['publik_pin_enabled' => true]);

            return redirect()->route('pengaturan.index')->with('success', 'Akses Mode Publik dengan PIN diaktifkan.');
        }

        // Nonaktifkan (tanpa hapus hash — bisa re-aktif nanti)
        $settings->update(['publik_pin_enabled' => false]);

        return redirect()->route('pengaturan.index')->with('success', 'PIN Mode Publik dinonaktifkan — akses publik kembali terbuka tanpa PIN (PIN lama tetap tersimpan).');
    }
}
