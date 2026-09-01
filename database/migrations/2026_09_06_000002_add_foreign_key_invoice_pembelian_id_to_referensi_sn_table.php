<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referensi_sn', function (Blueprint $table) {
            $table->foreign('invoice_pembelian_id')
                ->references('id')->on('invoice_pembelian')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('referensi_sn', function (Blueprint $table) {
            $table->dropForeign(['invoice_pembelian_id']);
        });
    }
};
