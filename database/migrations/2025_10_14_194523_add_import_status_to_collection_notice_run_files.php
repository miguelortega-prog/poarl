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
        Schema::table('collection_notice_run_files', function (Blueprint $table) {
            // Estado de la importación del archivo
            $table->enum('import_status', ['pending', 'processing', 'completed', 'failed'])
                ->default('pending')
                ->after('uploaded_by')
                ->comment('Estado de la importación: pending, processing, completed, failed');

            // Timestamps de la importación
            $table->timestamp('import_started_at')
                ->nullable()
                ->after('import_status')
                ->comment('Fecha/hora de inicio de la importación');

            $table->timestamp('import_completed_at')
                ->nullable()
                ->after('import_started_at')
                ->comment('Fecha/hora de finalización de la importación');

            // Mensaje de error si la importación falla
            $table->text('import_error')
                ->nullable()
                ->after('import_completed_at')
                ->comment('Mensaje de error si la importación falla');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collection_notice_run_files', function (Blueprint $table) {
            $table->dropColumn([
                'import_status',
                'import_started_at',
                'import_completed_at',
                'import_error',
            ]);
        });
    }
};
