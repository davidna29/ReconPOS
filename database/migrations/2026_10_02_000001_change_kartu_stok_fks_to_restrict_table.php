<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cegah stok yatim (orphan): FK kartu_stok sebelumnya nullOnDelete (SET NULL) —
     * invoice/penjualan yang dihapus di luar alur aplikasi membuat baris kartu jadi NULL
     * dan stoknya tidak bisa di-reverse. Ubah ke RESTRICT: invoice/transaksi yang masih
     * punya baris kartu_stok TIDAK bisa dihapus kecuali lewat alur aplikasi
     * (batalEfekPembelian/batalEfekPenjualan yang menghapus baris kartu dulu).
     */
    public function up(): void
    {
        Schema::table('kartu_stok', function (Blueprint $table) {
            $table->dropForeign(['transaksi_penjualan_id']);
            $table->dropForeign(['invoice_pembelian_id']);
        });

        Schema::table('kartu_stok', function (Blueprint $table) {
            $table->foreign('transaksi_penjualan_id')
                ->references('id')->on('transaksi_penjualan')
                ->restrictOnDelete();
            $table->foreign('invoice_pembelian_id')
                ->references('id')->on('invoice_pembelian')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kartu_stok', function (Blueprint $table) {
            $table->dropForeign(['transaksi_penjualan_id']);
            $table->dropForeign(['invoice_pembelian_id']);
        });

        Schema::table('kartu_stok', function (Blueprint $table) {
            $table->foreign('transaksi_penjualan_id')
                ->references('id')->on('transaksi_penjualan')
                ->nullOnDelete();
            $table->foreign('invoice_pembelian_id')
                ->references('id')->on('invoice_pembelian')
                ->nullOnDelete();
        });
    }
};
