<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_generate', function (Blueprint $table) {
            $table->id();
            $table->date('periode_mulai')->index();
            $table->date('periode_selesai');
            $table->unsignedBigInteger('target_omset');
            $table->json('parameter_json')->nullable();
            $table->string('status', 12)->default('draft');               // draft | tersimpan
            $table->unsignedBigInteger('total_generate')->nullable();
            $table->integer('selisih')->nullable();
            $table->string('keterangan')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_generate');
    }
};
