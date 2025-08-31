<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('original_path');
            $table->string('hls_master_path')->nullable();
            $table->string('hls_480p_path')->nullable();
            $table->string('hls_360p_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->integer('duration')->nullable(); // seconds
            $table->enum('status', ['pending','processing','ready','failed'])->default('pending');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('videos');
    }
};
