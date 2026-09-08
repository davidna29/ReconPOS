<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penanda grup bulk pada pembayaran hutang — satu UUID per eksekusi
     * bayar-bulk (lihat PembelianService::bayarBulk). NULL = bayar satuan.
     * Dipakai tombol "Batalkan bulk ini" (revert sekaligus).
     */
    public function up(): void
    {
        Schema::table('pembayaran_hutang', function (Blueprint $table) {
            $table->string('grup_bulk', 36)->nullable()->after('catatan');
        });
    }

    public function down(): void
    {
        Schema::table('pembayaran_hutang', function (Blueprint $table) {
            $table->dropColumn('grup_bulk');
        });
    }
};
