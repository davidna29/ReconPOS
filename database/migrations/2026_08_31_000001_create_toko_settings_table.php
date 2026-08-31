<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('toko_settings', function (Blueprint $table) {
            $table->id();
            $table->string('nama_toko')->default('Bengkel Saya');
            $table->text('alamat')->nullable();
            $table->string('telepon')->nullable();
            $table->string('logo')->nullable();
            $table->unsignedInteger('margin_default')->default(20);
            $table->string('jam_buka', 5)->default('08:00');
            $table->string('jam_tutup', 5)->default('17:00');
            $table->json('hari_libur')->nullable();            // {"dates":["2026-06-17"], "weekly":["Sun"]}
            $table->unsignedInteger('ambang_stok_menipis')->default(3);
            $table->json('parameter_generate')->nullable();    // {"nota_min":1,"nota_max":8,"toleransi_persen":2,"variasi_min":-10,"variasi_max":0}
            $table->string('template_version')->default('v1');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('toko_settings');
    }
};
