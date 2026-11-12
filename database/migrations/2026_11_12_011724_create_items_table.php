<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('judul', 150);
            $table->text('deskripsi')->nullable();
            $table->string('jenis', 20);
            $table->string('kategori', 50);
            $table->string('kondisi', 30)->nullable();
            $table->unsignedInteger('jumlah')->default(1);
            $table->string('foto')->nullable();
            $table->string('area', 100);
            $table->text('lokasi_detail')->nullable();
            $table->timestamp('waktu_dibuat_makanan')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 20)->default('available');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'expires_at']);
            $table->index(['jenis', 'kategori']);
            $table->index('area');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};