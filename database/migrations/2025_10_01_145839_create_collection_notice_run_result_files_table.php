<?php

declare(strict_types=1);

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
        Schema::create('collection_notice_run_result_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_notice_run_id')
                ->constrained('collection_notice_runs')
                ->cascadeOnDelete();
            $table->string('file_type', 50)->comment('Tipo de archivo: excluidos, procesados, errores, etc.');
            $table->string('file_name')->comment('Nombre del archivo generado');
            $table->string('disk', 50)->default('collection')->comment('Disco donde se almacena');
            $table->string('path')->comment('Ruta relativa del archivo');
            $table->bigInteger('size')->default(0)->comment('Tamaño del archivo en bytes');
            $table->integer('records_count')->default(0)->comment('Número de registros en el archivo');
            $table->json('metadata')->nullable()->comment('Metadata adicional del archivo');
            $table->timestamps();

            $table->index('collection_notice_run_id');
            $table->index('file_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collection_notice_run_result_files');
    }
};
