<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PPN otomatis: tarif global (%) + PPN per-SKU (Rp) untuk override Excel.
     * - toko_settings.ppn_persen: tarif default 11, bisa diubah di Pengaturan.
     * - invoice_pembelian_item.ppn: nominal Rp per baris (0 = ikut auto / tanpa override).
     */
    public function up(): void
    {
        Schema::table('toko_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('ppn_persen')->default(11)->after('margin_default');
        });

        Schema::table('invoice_pembelian_item', function (Blueprint $table) {
            $table->unsignedBigInteger('ppn')->default(0)->after('diskon_item');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_pembelian_item', function (Blueprint $table) {
            $table->dropColumn('ppn');
        });

        Schema::table('toko_settings', function (Blueprint $table) {
            $table->dropColumn('ppn_persen');
        });
    }
};
