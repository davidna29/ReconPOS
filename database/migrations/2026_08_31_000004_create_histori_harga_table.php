<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('histori_harga', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barang_id')->constrained('barang')->cascadeOnDelete();
            $table->unsignedBigInteger('harga_beli');
            $table->unsignedInteger('margin_dipakai')->nullable();
            $table->unsignedBigInteger('harga_jual_efektif');
            $table->dateTime('tanggal_berlaku')->index();
            $table->foreignId('invoice_pembelian_id')->nullable()->constrained('invoice_pembelian')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('histori_harga');
    }
};
