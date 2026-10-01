<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot cabang pada baris pembayaran: riwayat tak bergeser bila
     * cabang faktur diubah kemudian. Nullable (baris pra-fitur di-backfill
     * dari cabang faktur saat ini; bila faktur tak bercabang -> tetap NULL).
     */
    public function up(): void
    {
        Schema::table('pembayaran_hutang', function (Blueprint $table) {
            $table->string('cabang', 50)->nullable()->after('grup_bulk');
        });

        // Backfill portabel (tanpa update-join yg tak didukung SQLite):
        // salin cabang faktur saat ini ke baris bayar yg masih NULL.
        DB::table('pembayaran_hutang')->whereNull('cabang')->orderBy('id')->chunk(500, function ($rows) {
            foreach ($rows as $r) {
                $cabang = DB::table('invoice_pembelian')->where('id', $r->invoice_pembelian_id)->value('cabang');
                if (is_string($cabang) && trim($cabang) !== '') {
                    DB::table('pembayaran_hutang')->where('id', $r->id)->update(['cabang' => $cabang]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('pembayaran_hutang', function (Blueprint $table) {
            $table->dropColumn('cabang');
        });
    }
};
