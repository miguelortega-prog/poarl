<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notice_data_source_columns', function (Blueprint $table) {
            $table->id();

            $table->foreignId('notice_data_source_id')
                ->constrained('notice_data_sources')
                ->cascadeOnDelete();

            $table->string('column_name', 128);
            $table->timestampsTz();

            $table->unique(['notice_data_source_id', 'column_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_data_source_columns');
    }
};
