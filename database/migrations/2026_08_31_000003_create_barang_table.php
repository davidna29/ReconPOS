<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barang', function (Blueprint $table) {
            $table->id();
            $table->string('tipe_item', 10)->default('barang');          // barang | jasa (disiapkan utk masa depan)
            $table->string('kode_barang', 50)->unique();
            $table->string('nama');
            $table->text('deskripsi')->nullable();
            $table->foreignId('kategori_id')->nullable()->constrained('kategori_barang')->nullOnDelete();
            $table->string('satuan', 20)->default('pcs');
            $table->string('sn_atau_non_sn', 10)->default('NON_SN');      // SN | NON_SN
            $table->unsignedBigInteger('harga_beli_terakhir')->default(0);
            $table->unsignedInteger('margin_override')->nullable();        // persen, override margin global
            $table->string('mode_harga_jual', 20)->default('otomatis');   // otomatis | manual_terkunci
            $table->unsignedBigInteger('harga_jual')->default(0);
            $table->integer('stock')->default(0)->index();
            $table->string('lokasi_rak', 80)->nullable();
            $table->string('status', 30)->default('Dijual & Untuk Servis');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barang');
    }
};
