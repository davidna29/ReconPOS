<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referensi_sn', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barang_id')->constrained('barang')->cascadeOnDelete();
            $table->string('no_sn', 80);
            $table->string('status', 12)->default('tersedia');            // tersedia | terjual
            $table->foreignId('invoice_pembelian_id')->nullable();     // FK ditambahkan di migration terpisah (urutan MySQL)
            $table->foreignId('transaksi_penjualan_id')->nullable(); // FK ditambahkan di migration terpisah (urutan MySQL)
            $table->unique(['barang_id', 'no_sn']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referensi_sn');
    }
};
