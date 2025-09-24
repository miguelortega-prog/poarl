<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notice_data_sources', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->unsignedInteger('num_columns')->default(0);
            $table->string('extension')->default('csv');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_data_sources');
    }
};
