<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referensi_sn', function (Blueprint $table) {
            $table->foreign('transaksi_penjualan_id')
                ->references('id')->on('transaksi_penjualan')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('referensi_sn', function (Blueprint $table) {
            $table->dropForeign(['transaksi_penjualan_id']);
        });
    }
};
