<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_batch', function (Blueprint $table) {
            $table->id();
            $table->string('format', 5)->default('A');                    // A | B
            $table->date('filter_mulai')->nullable();
            $table->date('filter_selesai')->nullable();
            $table->string('filter_bulan', 7)->nullable();                // misal 2026-06
            $table->unsignedInteger('jumlah_file')->default(1);
            $table->json('file_names')->nullable();
            $table->boolean('status_upload_manual')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_batch');
    }
};
