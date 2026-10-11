<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('karyawans', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 100);
            // Kunci unik upsert + CounterpartTin (NPWP/NIK 16 digit)
            $table->string('nik', 16)->unique();
            $table->string('status_pegawai', 10)->default('Resident');
            $table->string('passport', 50)->nullable();
            $table->string('ptkp', 5)->default('TK/0');
            $table->string('posisi', 60)->default('Staff');
            $table->string('sertifikat', 5)->default('N/A');
            $table->string('kode_objek', 10)->default('21-100-01');
            $table->string('id_tku', 22)->nullable();
            // Link berkas: teks URL (Drive/dll), bukan upload
            $table->string('link_ktp', 500)->nullable();
            $table->string('link_kk', 500)->nullable();
            $table->string('link_berkas_1', 500)->nullable();
            $table->string('link_berkas_2', 500)->nullable();
            $table->string('link_berkas_3', 500)->nullable();
            $table->boolean('is_aktif')->default(true);
            $table->timestamps();

            $table->index('is_aktif');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('karyawans');
    }
};
