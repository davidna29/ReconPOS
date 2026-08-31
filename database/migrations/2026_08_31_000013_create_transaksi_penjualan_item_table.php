<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaksi_penjualan_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaksi_penjualan_id')->constrained('transaksi_penjualan')->cascadeOnDelete();
            $table->foreignId('barang_id')->constrained('barang');
            $table->unsignedInteger('qty');
            $table->unsignedBigInteger('harga_jual');
            $table->string('no_sn', 80)->nullable();
            $table->unsignedBigInteger('subtotal');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaksi_penjualan_item');
    }
};
