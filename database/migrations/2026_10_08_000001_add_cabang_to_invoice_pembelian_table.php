<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Beban hutang per cabang: kolom teks bebas nullable di invoice_pembelian.
     * Sengaja TANPA tabel master/FK (cukup utk grouping rekap;
     * hindari jebakan urutan FK MySQL — lihat AI-HANDOFF §6).
     * Hanya invoice jenis=hutang yang diberi beban (aturan di controller/service).
     */
    public function up(): void
    {
        Schema::table('invoice_pembelian', function (Blueprint $table) {
            $table->string('cabang', 50)->nullable()->after('sumber');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_pembelian', function (Blueprint $table) {
            $table->dropColumn('cabang');
        });
    }
};
