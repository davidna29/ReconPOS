<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Header riwayat bayar-bulk: satu baris per eksekusi (laman khusus).
     * Baris pembayaran menunjuk ke sini via pembayaran_hutang.grup_bulk = uuid.
     * Dibatalkan = header + baris dihapus (riwayat hilang, sisa revert).
     */
    public function up(): void
    {
        Schema::create('bulk_bayar_hutang', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->foreignId('supplier_id')->constrained('supplier');
            $table->date('tanggal_bayar');
            $table->unsignedBigInteger('nominal_bayar');
            $table->unsignedBigInteger('dialokasi')->default(0);
            $table->unsignedBigInteger('kembalian')->default(0);
            $table->string('bukti_url', 1000)->nullable();
            $table->string('catatan', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_bayar_hutang');
    }
};
