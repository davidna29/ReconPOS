<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\TokoSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BarangTest extends TestCase
{
    use RefreshDatabase;

    private function authUser(): User
    {
        $u = User::create(['name' => 'Admin', 'email' => 'a@a.id', 'password' => 'pass1234']);
        $this->actingAs($u);

        return $u;
    }

    public function test_daftar_barang(): void
    {
        $this->authUser();
        Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-1', 'nama' => 'Oli', 'sn_atau_non_sn' => 'NON_SN', 'harga_jual' => 50000]);
        $this->get(route('barang.index'))->assertOk()->assertSee('Oli');
    }

    public function test_kode_barang_unik_ditolak(): void
    {
        $this->authUser();
        Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-DUP', 'nama' => 'Satu', 'sn_atau_non_sn' => 'NON_SN']);
        $this->post(route('barang.store'), [
            'kode_barang' => 'B-DUP', 'nama' => 'Dua', 'sn_atau_non_sn' => 'NON_SN', 'status' => 'Dijual',
        ])->assertSessionHasErrors('kode_barang');
    }

    public function test_store_kode_duplikat_beda_case_ditolak(): void
    {
        $this->authUser();
        Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'ABC-1', 'nama' => 'Satu', 'sn_atau_non_sn' => 'NON_SN']);
        $this->post(route('barang.store'), [
            'kode_barang' => 'abc-1', 'nama' => 'Satu Lagi', 'sn_atau_non_sn' => 'NON_SN',
            'mode_harga_jual' => 'otomatis', 'status' => 'Dijual',
        ])->assertSessionHasErrors('kode_barang');
        $this->assertSame(1, Barang::count());
    }

    public function test_kode_barang_readonly_saat_edit(): void
    {
        $this->authUser();
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-FIX', 'nama' => 'X', 'sn_atau_non_sn' => 'NON_SN']);
        $this->get(route('barang.edit', $b))->assertSee('readonly');
    }

    public function test_hapus_barang_terpakai_diblokir(): void
    {
        $this->authUser();
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-PAKAI', 'nama' => 'Y', 'sn_atau_non_sn' => 'NON_SN']);
        $s = \App\Models\Supplier::create(['nama' => 'S', 'kontak' => '1']);
        \App\Services\PembelianService::class === null ?: null;
        app(\App\Services\PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'cash', 'bayar_atau_dp' => 100000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 1, 'diskon_item' => 0]],
        ]);
        $this->delete(route('barang.destroy', $b))->assertSessionHas('error');
        $this->assertDatabaseHas('barang', ['id' => $b->id]);
    }

    public function test_opname_wajib_alasan_dan_tercatat(): void
    {
        $this->authUser();
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-OP', 'nama' => 'Z', 'sn_atau_non_sn' => 'NON_SN', 'stock' => 5]);
        $this->post(route('barang.opname', $b), ['stok_fisik' => 3, 'alasan' => ''])
            ->assertSessionHasErrors('alasan');
        $this->post(route('barang.opname', $b), ['stok_fisik' => 3, 'alasan' => 'opname bulanan'])
            ->assertSessionHas('success');
        $this->assertEquals(3, $b->fresh()->stock);
        $this->assertDatabaseHas('kartu_stok', ['barang_id' => $b->id, 'jenis' => 'opname', 'qty' => -2]);
    }

    public function test_mode_ukur_otomatis_bikin_field_harga_readonly(): void
    {
        $this->authUser();
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-MD', 'nama' => 'M', 'sn_atau_non_sn' => 'NON_SN', 'mode_harga_jual' => 'otomatis']);
        $this->get(route('barang.edit', $b))->assertSee('harga-jual-input', false);
    }

    public function test_store_menyimpan_merk_varian_ukuran_dengan_normalisasi(): void
    {
        $this->authUser();
        $this->post(route('barang.store'), [
            'kode_barang' => 'BL-26570R17-SAV-MTP', 'nama' => 'BAN LUAR 265/70 R17 SAVERO M/T PRO',
            'sn_atau_non_sn' => 'NON_SN', 'mode_harga_jual' => 'otomatis', 'status' => 'Dijual & Untuk Servis',
            'merk' => '  Gajah Tunggal   (GT) ', 'varian' => 'SAVERO  M/T PRO', 'ukuran_ban' => '265/70  R17',
        ])->assertSessionHas('success');

        $b = Barang::where('kode_barang', 'BL-26570R17-SAV-MTP')->firstOrFail();
        $this->assertSame('Gajah Tunggal (GT)', $b->merk);
        $this->assertSame('SAVERO M/T PRO', $b->varian);
        $this->assertSame('265/70 R17', $b->ukuran_ban);
        $this->get(route('barang.index'))->assertSee('Gajah Tunggal (GT)');
    }

    public function test_update_merk_varian_ukuran_dan_validasi_maks(): void
    {
        $this->authUser();
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-MVU', 'nama' => 'Ban', 'sn_atau_non_sn' => 'NON_SN']);
        $this->put(route('barang.update', $b), [
            'nama' => 'Ban', 'sn_atau_non_sn' => 'NON_SN', 'mode_harga_jual' => 'otomatis', 'status' => 'Dijual',
            'merk' => 'HANKOOK', 'varian' => 'VANTRA', 'ukuran_ban' => '195 R15',
        ])->assertSessionHas('success');
        $this->assertSame('HANKOOK', $b->fresh()->merk);

        $this->put(route('barang.update', $b), [
            'nama' => 'Ban', 'sn_atau_non_sn' => 'NON_SN', 'mode_harga_jual' => 'otomatis', 'status' => 'Dijual',
            'merk' => str_repeat('M', 101),
        ])->assertSessionHasErrors('merk');
    }

    public function test_index_filter_merk_dan_cari_kena_ukuran(): void
    {
        $this->authUser();
        Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-GT', 'nama' => 'Ban GT', 'sn_atau_non_sn' => 'NON_SN', 'merk' => 'Gajah Tunggal (GT)', 'ukuran_ban' => '265/70 R17']);
        Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-HK', 'nama' => 'Ban HK', 'sn_atau_non_sn' => 'NON_SN', 'merk' => 'HANKOOK', 'ukuran_ban' => '195 R15']);

        // strip "Baru Ditambahkan" tidak ikut filter → assert pada sel tabel (markup unik per baris)
        $selGT = '<td data-label="Kode Barang" class="mono text-secondary">B-GT</td>';
        $selHK = '<td data-label="Kode Barang" class="mono text-secondary">B-HK</td>';

        $this->get(route('barang.index', ['merk' => 'HANKOOK']))->assertOk()->assertSee($selHK, false)->assertDontSee($selGT, false);
        $this->get(route('barang.index', ['q' => '265/70']))->assertOk()->assertSee($selGT, false)->assertDontSee($selHK, false);
        $this->get(route('barang.index'))->assertOk()->assertSee('Semua merk');
    }

    public function test_index_import_section_bisa_dilipat_dan_file_picker_rapi(): void
    {
        $this->authUser();
        Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'PB-BA', 'nama' => 'Ban Uji', 'sn_atau_non_sn' => 'NON_SN']);

        $html = $this->get(route('barang.index'))->assertOk()->getContent();
        // collapse: label mobile + konten; file input sr-only + penampung nama file
        $this->assertStringContainsString('collapse-import-barang', $html);
        $this->assertStringContainsString('data-filename', $html);
        // kedua form + tombol lama tetap ada (tidak merusak alur upload)
        $this->assertStringContainsString(route('barang.template.import'), $html);
        $this->assertStringContainsString(route('barang.templateHarga.import'), $html);
        $this->assertStringContainsString('Upload Template Excel', $html);
        $this->assertStringContainsString('Update Harga Excel', $html);
    }
}
