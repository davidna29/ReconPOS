<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@test.id', 'password' => 'rahasia123']);
    }

    public function test_login_page_tampil(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Mechanic Hub');
    }

    public function test_login_valid_masuk_dashboard(): void
    {
        $this->admin();
        $this->post(route('login.attempt'), ['email' => 'admin@test.id', 'password' => 'rahasia123'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_login_invalid_ditolak(): void
    {
        $this->admin();
        $this->post(route('login.attempt'), ['email' => 'admin@test.id', 'password' => 'salah'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_halaman_terproteksi_mengarahkan_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('barang.index'))->assertRedirect(route('login'));
    }

    public function test_logout(): void
    {
        $this->admin();
        $this->post(route('login.attempt'), ['email' => 'admin@test.id', 'password' => 'rahasia123']);
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_tidak_ada_registrasi_publik(): void
    {
        $this->get('/register')->assertNotFound();
    }
}
