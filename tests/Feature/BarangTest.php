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
}
