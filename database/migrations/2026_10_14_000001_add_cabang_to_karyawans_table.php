<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('karyawans', function (Blueprint $table) {
            // Kategori internal saja — tidak ikut export Coretax.
            $table->string('cabang', 50)->nullable()->after('posisi');
            $table->index('cabang');
        });
    }

    public function down(): void
    {
        Schema::table('karyawans', function (Blueprint $table) {
            $table->dropIndex(['cabang']);
            $table->dropColumn('cabang');
        });
    }
};
