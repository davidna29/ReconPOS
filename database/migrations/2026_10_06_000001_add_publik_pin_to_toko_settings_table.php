<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('toko_settings', function (Blueprint $table) {
            $table->boolean('publik_pin_enabled')->default(false)->after('template_version');
            $table->string('publik_pin_hash')->nullable()->after('publik_pin_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('toko_settings', function (Blueprint $table) {
            $table->dropColumn(['publik_pin_enabled', 'publik_pin_hash']);
        });
    }
};
