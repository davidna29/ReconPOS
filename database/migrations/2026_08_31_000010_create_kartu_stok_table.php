<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kartu_stok', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barang_id')->constrained('barang')->cascadeOnDelete();
            $table->dateTime('tanggal')->index();
            $table->string('jenis', 25);                                  // masuk_pembelian | keluar_penjualan | stok_awal | opname
            $table->integer('qty');                                       // + masuk, - keluar
            $table->foreignId('invoice_pembelian_id')->nullable()->constrained('invoice_pembelian')->nullOnDelete();
            $table->foreignId('transaksi_penjualan_id')->nullable()->constrained('transaksi_penjualan')->nullOnDelete();
            $table->string('keterangan')->nullable();
            $table->timestamps();
            $table->index(['barang_id', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kartu_stok');
    }
};
