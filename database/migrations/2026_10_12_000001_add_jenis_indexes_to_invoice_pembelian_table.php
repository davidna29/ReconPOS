<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index filter beban hutang (anti-ledakan faktur): kolom `jenis`
     * dipakai di semua query rekap tapi belum terindeks.
     */
    public function up(): void
    {
        Schema::table('invoice_pembelian', function (Blueprint $table) {
            $table->index(['jenis', 'tanggal_transaksi'], 'inv_jenis_tanggal_idx');
            $table->index(['jenis', 'cabang'], 'inv_jenis_cabang_idx');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_pembelian', function (Blueprint $table) {
            $table->dropIndex('inv_jenis_tanggal_idx');
            $table->dropIndex('inv_jenis_cabang_idx');
        });
    }
};
