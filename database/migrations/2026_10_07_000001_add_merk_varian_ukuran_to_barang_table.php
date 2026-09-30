<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Atribut ban: merk, varian, ukuran (kolom teks bebas nullable).
     * Sengaja TANPA tabel master/FK (cukup utk grouping Rekap Stok;
     * hindari jebakan urutan FK MySQL — lihat AI-HANDOFF §6).
     */
    public function up(): void
    {
        Schema::table('barang', function (Blueprint $table) {
            $table->string('merk', 100)->nullable()->after('kategori_id');
            $table->string('varian', 100)->nullable()->after('merk');
            $table->string('ukuran_ban', 50)->nullable()->after('varian');
        });
    }

    public function down(): void
    {
        Schema::table('barang', function (Blueprint $table) {
            $table->dropColumn(['merk', 'varian', 'ukuran_ban']);
        });
    }
};
