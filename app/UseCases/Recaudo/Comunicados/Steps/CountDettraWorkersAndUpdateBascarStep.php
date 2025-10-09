<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Contar trabajadores activos de DETTRA y actualizar BASCAR.
 *
 * Este paso realiza:
 * 1. Crea columnas en BASCAR (idempotente):
 *    - cantidad_trabajadores (INTEGER)
 *    - observacion_trabajadores (TEXT)
 *
 * 2. Cruce BASCAR con DETTRA:
 *    - BASCAR.NUM_TOMADOR = DETTRA.NIT (empleador)
 *
 * 3. Para registros que SÍ cruzan:
 *    - Cuenta NRO_DOCUMENTO distintos en DETTRA (trabajadores activos)
 *    - Actualiza cantidad_trabajadores = conteo
 *    - observacion_trabajadores = NULL
 *
 * 4. Para registros que NO cruzan:
 *    - cantidad_trabajadores = 1
 *    - observacion_trabajadores = "Sin trabajadores activos"
 */
final class CountDettraWorkersAndUpdateBascarStep implements ProcessingStepInterface
{

    public function getName(): string
    {
        return 'Contar trabajadores de DETTRA y actualizar BASCAR';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        $startTime = microtime(true);

        Log::info('👥 Contando trabajadores activos de DETTRA', [
            'step' => self::class,
            'run_id' => $run->id,
        ]);

        // Paso 1: Crear columnas en BASCAR si no existen
        $this->ensureBascarColumns();

        // Paso 2: Contar registros en BASCAR
        $totalBascar = DB::table('data_source_bascar')
            ->where('run_id', $run->id)
            ->count();

        Log::info('Registros en BASCAR a actualizar', [
            'run_id' => $run->id,
            'total' => $totalBascar,
        ]);

        // Paso 3: Actualizar registros que SÍ cruzan con DETTRA
        $updated = $this->updateBascarWithWorkerCount($run);

        // Paso 4: Actualizar registros que NO cruzan con DETTRA
        $updatedWithoutWorkers = $this->updateBascarWithoutWorkers($run);

        // Paso 5: Verificar resultados
        $withWorkers = DB::table('data_source_bascar')
            ->where('run_id', $run->id)
            ->whereNotNull('cantidad_trabajadores')
            ->whereNull('observacion_trabajadores')
            ->count();

        $withoutWorkers = DB::table('data_source_bascar')
            ->where('run_id', $run->id)
            ->where('cantidad_trabajadores', 1)
            ->where('observacion_trabajadores', 'Sin trabajadores activos')
            ->count();

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('✅ Conteo de trabajadores completado', [
            'run_id' => $run->id,
            'total_bascar' => $totalBascar,
            'with_workers' => $withWorkers,
            'without_workers' => $withoutWorkers,
            'duration_ms' => $duration,
        ]);

        // Warning si hay registros sin actualizar
        $totalUpdated = $withWorkers + $withoutWorkers;
        if ($totalUpdated !== $totalBascar) {
            Log::warning('⚠️  Algunos registros no fueron actualizados', [
                'run_id' => $run->id,
                'expected' => $totalBascar,
                'actual' => $totalUpdated,
                'missing' => $totalBascar - $totalUpdated,
            ]);
        }
    }

    /**
     * Asegura que existan las columnas de trabajadores en BASCAR.
     */
    private function ensureBascarColumns(): void
    {
        $tableName = 'data_source_bascar';

        // Crear columna cantidad_trabajadores si no existe
        if (!$this->columnExists($tableName, 'cantidad_trabajadores')) {
            Log::info('Creando columna cantidad_trabajadores en BASCAR', [
                'table' => $tableName,
            ]);

            DB::statement("
                ALTER TABLE {$tableName}
                ADD COLUMN cantidad_trabajadores INTEGER
            ");

            Log::info('✅ Columna cantidad_trabajadores creada');
        }

        // Crear columna observacion_trabajadores si no existe
        if (!$this->columnExists($tableName, 'observacion_trabajadores')) {
            Log::info('Creando columna observacion_trabajadores en BASCAR', [
                'table' => $tableName,
            ]);

            DB::statement("
                ALTER TABLE {$tableName}
                ADD COLUMN observacion_trabajadores TEXT
            ");

            Log::info('✅ Columna observacion_trabajadores creada');
        }
    }

    /**
     * Actualiza BASCAR con conteo de trabajadores de DETTRA.
     *
     * Lógica:
     * - Cruza BASCAR.NUM_TOMADOR = DETTRA.NRO_DOCUMTO (empleador)
     * - Cuenta NIT distintos en DETTRA (trabajadores activos del empleador)
     * - Actualiza cantidad_trabajadores y pone observacion_trabajadores = NULL
     */
    private function updateBascarWithWorkerCount(CollectionNoticeRun $run): int
    {
        Log::info('Actualizando registros que cruzan con DETTRA', [
            'run_id' => $run->id,
        ]);

        // Actualizar usando subquery para contar trabajadores
        $updated = DB::update("
            UPDATE data_source_bascar b
            SET
                cantidad_trabajadores = worker_counts.count,
                observacion_trabajadores = NULL
            FROM (
                SELECT
                    nro_documto,
                    COUNT(DISTINCT nit) as count
                FROM data_source_dettra
                WHERE run_id = ?
                    AND nro_documto IS NOT NULL
                    AND nro_documto != ''
                    AND nit IS NOT NULL
                    AND nit != ''
                GROUP BY nro_documto
            ) worker_counts
            WHERE b.num_tomador = worker_counts.nro_documto
                AND b.run_id = ?
                AND b.num_tomador IS NOT NULL
                AND b.num_tomador != ''
        ", [$run->id, $run->id]);

        Log::info('Registros actualizados con trabajadores de DETTRA', [
            'run_id' => $run->id,
            'updated' => $updated,
        ]);

        return $updated;
    }

    /**
     * Actualiza BASCAR para registros sin trabajadores en DETTRA.
     *
     * Asigna valores por defecto a registros que no cruzaron.
     */
    private function updateBascarWithoutWorkers(CollectionNoticeRun $run): int
    {
        Log::info('Actualizando registros sin trabajadores en DETTRA', [
            'run_id' => $run->id,
        ]);

        $updated = DB::update("
            UPDATE data_source_bascar
            SET
                cantidad_trabajadores = 1,
                observacion_trabajadores = 'Sin trabajadores activos'
            WHERE run_id = ?
                AND cantidad_trabajadores IS NULL
        ", [$run->id]);

        Log::info('Registros sin trabajadores actualizados', [
            'run_id' => $run->id,
            'updated' => $updated,
        ]);

        return $updated;
    }

    /**
     * Verifica si una columna existe en una tabla.
     */
    private function columnExists(string $tableName, string $columnName): bool
    {
        $result = DB::select("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_name = ?
            AND column_name = ?
        ", [$tableName, $columnName]);

        return count($result) > 0;
    }
}
