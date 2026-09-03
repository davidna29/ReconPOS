<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom PPN manual (Rp) pada invoice pembelian — nilai disalin dari e-Faktur supplier,
     * TANPA kalkulasi otomatis (PRD 5.1 perluasan). Konvensi uang: integer rupiah.
     */
    public function up(): void
    {
        Schema::table('invoice_pembelian', function (Blueprint $table) {
            $table->integer('ppn')->nullable()->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_pembelian', function (Blueprint $table) {
            $table->dropColumn('ppn');
        });
    }
};
