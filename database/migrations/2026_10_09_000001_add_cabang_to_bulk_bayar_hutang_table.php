<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cabang yg bebannya dibayar pada paket bulk (NULL = mode "Semua cabang"
     * atau data lama sebelum fitur; alokasi tetap FIFO dalam scope tsb).
     * Plain ADD COLUMN — aman MySQL shared-hosting, tanpa FK.
     */
    public function up(): void
    {
        Schema::table('bulk_bayar_hutang', function (Blueprint $table) {
            $table->string('cabang', 50)->nullable()->after('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_bayar_hutang', function (Blueprint $table) {
            $table->dropColumn('cabang');
        });
    }
};
