<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index filter + COUNT pagination rekap stok (anti-ledakan SKU).
     * LIKE %..% pencarian tetap full-scan (jujur tak terindeks).
     */
    public function up(): void
    {
        Schema::table('barang', function (Blueprint $table) {
            $table->index(['tipe_item', 'merk'], 'barang_tipe_merk_idx');
        });
    }

    public function down(): void
    {
        Schema::table('barang', function (Blueprint $table) {
            $table->dropIndex('barang_tipe_merk_idx');
        });
    }
};
