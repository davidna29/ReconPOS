<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_export_pos_config', function (Blueprint $table) {
            $table->id();
            $table->string('template_version', 20)->default('v1');
            $table->string('nama_kolom', 40);
            $table->unsignedInteger('urutan');
            $table->string('wajib_opsional', 10)->default('opsional');    // wajib | opsional
            $table->string('format', 40)->nullable();
            $table->string('nilai_default', 40)->nullable();
            $table->string('sumber_data', 80)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_export_pos_config');
    }
};
