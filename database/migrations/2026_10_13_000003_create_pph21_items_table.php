<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pph21_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('periode_id')->constrained('pph21_periodes')->cascadeOnDelete();
            $table->foreignId('karyawan_id')->constrained('karyawans')->cascadeOnDelete();
            // Snapshot kolom Coretax saat export (agar riwayat stabil walau master berubah)
            $table->string('status_pegawai', 10);
            $table->string('nik', 16);
            $table->string('passport', 50)->nullable();
            $table->string('ptkp', 5);
            $table->string('posisi', 60);
            $table->string('sertifikat', 5);
            $table->string('kode_objek', 10);
            $table->string('id_tku', 22);
            $table->unsignedBigInteger('gross');
            $table->decimal('rate', 8, 2);
            $table->boolean('is_rate_manual')->default(false);
            $table->timestamps();

            $table->unique(['periode_id', 'karyawan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pph21_items');
    }
};
