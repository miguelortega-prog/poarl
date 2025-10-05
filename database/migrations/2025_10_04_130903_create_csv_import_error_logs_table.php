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
        Schema::create('csv_import_error_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('collection_notice_runs')->onDelete('cascade');
            $table->string('data_source_code', 50);
            $table->string('table_name', 100);
            $table->unsignedBigInteger('line_number');
            $table->text('line_content')->nullable();
            $table->string('error_type', 100)->nullable();
            $table->text('error_message');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['run_id', 'data_source_code']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('csv_import_error_logs');
    }
};
