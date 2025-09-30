<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Paso 1: Agregar nuevos campos primero
        Schema::table('collection_notice_runs', function (Blueprint $table): void {
            $table->timestampTz('validated_at')->nullable()->after('started_at');
            $table->timestampTz('completed_at')->nullable()->after('validated_at');
            $table->timestampTz('failed_at')->nullable()->after('completed_at');
            $table->json('errors')->nullable()->after('duration_ms');
            $table->json('metadata')->nullable()->after('errors');
            $table->index('validated_at');
            $table->index('completed_at');
        });

        // Paso 2: Para PostgreSQL, necesitamos usar ALTER TYPE para modificar el enum
        DB::statement("ALTER TABLE collection_notice_runs DROP CONSTRAINT collection_notice_runs_status_check");

        // Paso 3: Cambiar el tipo de columna
        DB::statement("
            ALTER TABLE collection_notice_runs
            ALTER COLUMN status TYPE VARCHAR(50)
        ");

        // Paso 4: Migrar datos existentes
        DB::table('collection_notice_runs')
            ->where('status', 'ready')
            ->update(['status' => 'pending']);

        DB::table('collection_notice_runs')
            ->where('status', 'in_process')
            ->update(['status' => 'processing']);

        DB::table('collection_notice_runs')
            ->where('status', 'finished')
            ->update(['status' => 'completed']);

        DB::table('collection_notice_runs')
            ->where('status', 'closed')
            ->update(['status' => 'completed']);

        // Paso 5: Recrear el constraint con los nuevos valores
        DB::statement("
            ALTER TABLE collection_notice_runs
            ADD CONSTRAINT collection_notice_runs_status_check
            CHECK (status IN (
                'pending',
                'validating',
                'validation_failed',
                'validated',
                'processing',
                'completed',
                'failed',
                'cancelled'
            ))
        ");

        // Paso 6: Establecer el valor por defecto
        DB::statement("
            ALTER TABLE collection_notice_runs
            ALTER COLUMN status SET DEFAULT 'pending'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Paso 1: Eliminar el constraint actual
        DB::statement("ALTER TABLE collection_notice_runs DROP CONSTRAINT collection_notice_runs_status_check");

        // Paso 2: Migrar datos de vuelta (mapeo inverso)
        DB::table('collection_notice_runs')
            ->where('status', 'pending')
            ->update(['status' => 'ready']);

        DB::table('collection_notice_runs')
            ->whereIn('status', ['validating', 'validated', 'processing'])
            ->update(['status' => 'in_process']);

        DB::table('collection_notice_runs')
            ->whereIn('status', ['completed'])
            ->update(['status' => 'finished']);

        DB::table('collection_notice_runs')
            ->whereIn('status', ['validation_failed', 'failed'])
            ->update(['status' => 'closed']);

        // Paso 3: Recrear constraint original
        DB::statement("
            ALTER TABLE collection_notice_runs
            ADD CONSTRAINT collection_notice_runs_status_check
            CHECK (status IN (
                'ready',
                'in_process',
                'finished',
                'closed',
                'cancelled'
            ))
        ");

        // Paso 4: Establecer default original
        DB::statement("
            ALTER TABLE collection_notice_runs
            ALTER COLUMN status SET DEFAULT 'ready'
        ");

        // Paso 5: Eliminar columnas agregadas
        Schema::table('collection_notice_runs', function (Blueprint $table): void {
            $table->dropIndex(['validated_at']);
            $table->dropIndex(['completed_at']);

            $table->dropColumn([
                'validated_at',
                'completed_at',
                'failed_at',
                'errors',
                'metadata',
            ]);
        });
    }
};
