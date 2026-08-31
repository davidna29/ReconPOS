<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_pembelian_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_pembelian_id')->constrained('invoice_pembelian')->cascadeOnDelete();
            $table->foreignId('barang_id')->constrained('barang');
            $table->unsignedBigInteger('harga_beli');
            $table->unsignedInteger('qty');
            $table->unsignedBigInteger('diskon_item')->default(0);
            $table->unsignedBigInteger('sub_total');
            $table->string('no_sn', 80)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_pembelian_item');
    }
};
