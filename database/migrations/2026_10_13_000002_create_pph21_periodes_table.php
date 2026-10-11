<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pph21_periodes', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('masa');
            $table->unsignedSmallInteger('tahun');
            $table->date('tgl_pemotongan');
            // Snapshot NPWP pemotong CV saat periode dibuat (16 digit)
            $table->string('npwp_pemotong', 16);
            $table->timestamps();

            $table->unique(['masa', 'tahun']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pph21_periodes');
    }
};
