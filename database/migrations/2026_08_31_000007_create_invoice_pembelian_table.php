<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_pembelian', function (Blueprint $table) {
            $table->id();
            $table->string('no_invoice', 40)->unique();
            $table->foreignId('supplier_id')->constrained('supplier');
            $table->dateTime('tanggal_transaksi')->index();
            $table->string('jenis', 10)->default('cash');                 // cash | hutang
            $table->unsignedBigInteger('diskon_total')->default(0);
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('total_setelah_diskon');
            $table->unsignedBigInteger('bayar_atau_dp')->default(0);
            $table->date('jatuh_tempo')->nullable();
            $table->unsignedBigInteger('sisa_hutang')->default(0);
            $table->string('status', 15)->default('belum_lunas');         // lunas | belum_lunas
            $table->string('sumber', 15)->default('manual');              // manual | import_excel
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_pembelian');
    }
};
