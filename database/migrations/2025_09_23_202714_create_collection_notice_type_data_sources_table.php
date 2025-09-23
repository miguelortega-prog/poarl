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
        Schema::create('collection_notice_type_data_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_notice_type_id')
                ->constrained('collection_notice_types')
                ->cascadeOnDelete();

            $table->foreignId('notice_data_source_id')
                ->constrained('notice_data_sources')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['collection_notice_type_id', 'notice_data_source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collection_notice_type_data_sources');
    }
};
