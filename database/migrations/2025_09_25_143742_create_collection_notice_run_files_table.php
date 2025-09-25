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
        Schema::create('collection_notice_run_files', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('collection_notice_run_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('notice_data_source_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('original_name', 255);     
            $table->string('stored_name', 255);
            $table->string('disk', 60)->default('collection');
            $table->string('path', 1024);
            $table->unsignedBigInteger('size');
            $table->string('mime', 191)->nullable();
            $table->string('ext', 20)->nullable();
            $table->string('sha256', 64)->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['collection_notice_run_id', 'notice_data_source_id'], 'run_source_unique');
            $table->index(['notice_data_source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collection_notice_run_files');
    }
};
