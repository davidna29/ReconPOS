<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('toko_settings', function (Blueprint $table) {
            $table->string('npwp', 30)->nullable()->after('telepon');
        });
    }

    public function down(): void
    {
        Schema::table('toko_settings', function (Blueprint $table) {
            $table->dropColumn('npwp');
        });
    }
};
