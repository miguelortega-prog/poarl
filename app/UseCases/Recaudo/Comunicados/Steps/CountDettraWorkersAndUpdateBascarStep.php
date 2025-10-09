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
        Log::info('Contando trabajadores activos de DETTRA', ['run_id' => $run->id]);

        $this->ensureBascarColumns();
        $this->updateBascarWithWorkerCount($run);
        $this->updateBascarWithoutWorkers($run);

        Log::info('Conteo de trabajadores completado', ['run_id' => $run->id]);
    }

    /**
     * Asegura que existan las columnas de trabajadores en BASCAR.
     */
    private function ensureBascarColumns(): void
    {
        $tableName = 'data_source_bascar';

        if (!$this->columnExists($tableName, 'cantidad_trabajadores')) {
            DB::statement("
                ALTER TABLE {$tableName}
                ADD COLUMN cantidad_trabajadores INTEGER
            ");
        }

        if (!$this->columnExists($tableName, 'observacion_trabajadores')) {
            DB::statement("
                ALTER TABLE {$tableName}
                ADD COLUMN observacion_trabajadores TEXT
            ");
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

        return $updated;
    }

    /**
     * Actualiza BASCAR para registros sin trabajadores en DETTRA.
     *
     * Asigna valores por defecto a registros que no cruzaron.
     */
    private function updateBascarWithoutWorkers(CollectionNoticeRun $run): int
    {
        $updated = DB::update("
            UPDATE data_source_bascar
            SET
                cantidad_trabajadores = 1,
                observacion_trabajadores = 'Sin trabajadores activos'
            WHERE run_id = ?
                AND cantidad_trabajadores IS NULL
        ", [$run->id]);

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
