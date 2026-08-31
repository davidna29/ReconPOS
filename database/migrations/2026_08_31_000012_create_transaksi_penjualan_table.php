<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaksi_penjualan', function (Blueprint $table) {
            $table->id();
            $table->string('ref_transaksi', 40)->unique();
            $table->dateTime('tanggal_waktu')->index();
            $table->unsignedBigInteger('customer_id')->default(0);        // 0 = Customer Umum
            $table->string('jenis_transaksi', 10)->default('cash');       // cash | piutang
            $table->string('tipe_pembayaran', 10)->default('cash');       // cash | transfer
            $table->string('sumber', 15)->default('rekonstruksi');        // manual | rekonstruksi
            $table->string('status', 12)->default('tersimpan');           // draft | tersimpan
            $table->foreignId('batch_generate_id')->nullable()->constrained('batch_generate')->nullOnDelete();
            $table->unsignedBigInteger('total')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaksi_penjualan');
    }
};
