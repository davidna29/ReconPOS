<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\TokoSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PengaturanTest extends TestCase
{
    use RefreshDatabase;

    private function authUser(): User
    {
        $u = User::create(['name' => 'Admin', 'email' => 'set@a.id', 'password' => 'pass1234']);
        $this->actingAs($u);

        return $u;
    }

    public function test_halaman_pengaturan_muat(): void
    {
        $this->authUser();
        $this->get(route('pengaturan.index'))->assertOk()->assertSee('Template Export POS');
    }

    public function test_simpan_item_dikecualikan_default(): void
    {
        $this->authUser();
        $a = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'S-A', 'nama' => 'Satu', 'sn_atau_non_sn' => 'NON_SN']);
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'S-B', 'nama' => 'Dua', 'sn_atau_non_sn' => 'NON_SN']);

        $this->post(route('pengaturan.update'), [
            'nama_toko' => 'Toko Test',
            'margin_default' => 20,
            'jam_buka' => '08:00',
            'jam_tutup' => '17:00',
            'ambang_stok_menipis' => 3,
            'nota_min' => 1, 'nota_max' => 8, 'toleransi_persen' => 2,
            'variasi_min' => -10, 'variasi_max' => 0,
            'excluded_item_ids' => [(string) $a->id],
        ])->assertSessionHas('success');

        $pg = TokoSetting::utama()->fresh()->parameter_generate;
        $this->assertSame([$a->id], $pg['excluded_item_ids'] ?? null);
    }

    public function test_simpan_npwp(): void
    {
        $this->authUser();
        $this->post(route('pengaturan.update'), [
            'nama_toko' => 'Toko PPN Test',
            'npwp' => '01.234.567.8-901.000',
            'margin_default' => 20,
            'jam_buka' => '08:00', 'jam_tutup' => '17:00',
            'ambang_stok_menipis' => 3,
            'nota_min' => 1, 'nota_max' => 8, 'toleransi_persen' => 2,
            'variasi_min' => -10, 'variasi_max' => 0,
        ])->assertSessionHas('success');

        $this->assertSame('01.234.567.8-901.000', TokoSetting::utama()->fresh()->npwp);
    }

    public function test_parameter_lain_tetap_aman_saat_simpan(): void
    {
        $this->authUser();
        $this->post(route('pengaturan.update'), [
            'margin_default' => 25,
            'nota_min' => 2, 'nota_max' => 6, 'toleransi_persen' => 3,
            'variasi_min' => -5, 'variasi_max' => 0,
        ])->assertSessionHas('success');

        $pg = TokoSetting::utama()->fresh()->parameter_generate;
        $this->assertSame(25, (int) TokoSetting::utama()->fresh()->margin_default);
        $this->assertSame(2, $pg['nota_min']);
        $this->assertSame([], $pg['excluded_item_ids'] ?? []);
    }

    public function test_ubah_password_admin_sukses_dan_login_baru(): void
    {
        $u = $this->authUser();

        $this->post(route('pengaturan.password'), [
            'password_lama' => 'pass1234',
            'password' => 'gentot2026x',
            'password_confirmation' => 'gentot2026x',
        ])->assertSessionHas('success');

        $this->assertTrue(Hash::check('gentot2026x', $u->fresh()->password));
        $this->assertFalse(Hash::check('pass1234', $u->fresh()->password));

        // logout dulu (route login ber-middleware guest — user terautentikasi langsung di-redirect)
        $this->post(route('logout'))->assertRedirect(route('login'));

        // login dengan password baru berhasil, password lama ditolak
        $this->post(route('login'), ['email' => $u->email, 'password' => 'gentot2026x'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard'));
        $this->post(route('logout'));
        $this->post(route('login'), ['email' => $u->email, 'password' => 'pass1234'])
            ->assertSessionHasErrors('email');
    }

    public function test_ubah_password_admin_password_lama_salah(): void
    {
        $u = $this->authUser();

        $this->post(route('pengaturan.password'), [
            'password_lama' => 'salah123',
            'password' => 'gentot2026x',
            'password_confirmation' => 'gentot2026x',
        ])->assertSessionHas('error');

        $this->assertTrue(Hash::check('pass1234', $u->fresh()->password));
    }

    public function test_ubah_password_admin_validasi(): void
    {
        $this->authUser();

        // konfirmasi tidak cocok
        $this->post(route('pengaturan.password'), [
            'password_lama' => 'pass1234',
            'password' => 'gentot2026x',
            'password_confirmation' => 'beda12345',
        ])->assertSessionHasErrors('password');

        // terlalu pendek (< 8)
        $this->post(route('pengaturan.password'), [
            'password_lama' => 'pass1234',
            'password' => 'pendek',
            'password_confirmation' => 'pendek',
        ])->assertSessionHasErrors('password');
    }

    public function test_halaman_pengaturan_memuat_form_ubah_password(): void
    {
        $this->authUser();

        $this->get(route('pengaturan.index'))
            ->assertOk()
            ->assertSee('Keamanan — Ubah Password Admin')
            ->assertSee('name="password_lama"', false)
            ->assertSee('name="password_confirmation"', false);
    }

    public function test_form_terapkan_margin_berada_di_luar_form_utama(): void
    {
        // Regresi: form 'pengaturan.terapkan-margin' HARUS berada DI LUAR form utama 'pengaturan.update'
        // (form bersarang di HTML tidak valid — browser mengabaikan form dalam, tombol nyasar submit form utama).
        $this->authUser();
        $html = $this->get(route('pengaturan.index'))->assertOk()->getContent();

        $u = strpos($html, route('pengaturan.update'));
        $close = strpos($html, '</form>', $u !== false ? $u : 0);
        $t = strpos($html, route('pengaturan.terapkan-margin'));

        $this->assertNotFalse($u, 'Harus ada form utama pengaturan.update.');
        $this->assertNotFalse($close, 'Harus ada penutup form utama.');
        $this->assertNotFalse($t, 'Harus ada form terapkan-margin.');
        $this->assertGreaterThan($close, $t, 'Form terapkan margin harus DI LUAR form utama (sebelumnya bersarang, tombol tidak berfungsi).');
    }
}
