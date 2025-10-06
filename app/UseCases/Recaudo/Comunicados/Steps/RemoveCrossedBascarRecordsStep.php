<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Eliminar de BASCAR los registros que cruzaron con PAGAPL.
 *
 * Los aportantes que cruzaron con PAGAPL (que ya pagaron) fueron guardados
 * en el archivo excluidos{run_id}.csv en el paso anterior.
 *
 * Este paso los elimina físicamente de la tabla data_source_bascar para dejar
 * solo los aportantes morosos que continuarán en el flujo de procesamiento.
 *
 * Operación SQL:
 * DELETE FROM data_source_bascar
 * WHERE run_id = X
 *   AND EXISTS (
 *     SELECT 1 FROM data_source_pagapl
 *     WHERE composite_key = data_source_bascar.composite_key
 *       AND run_id = X
 *   )
 */
final class RemoveCrossedBascarRecordsStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Eliminar registros de BASCAR que cruzaron con PAGAPL';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        $startTime = microtime(true);
        $tableName = 'data_source_bascar';

        Log::info('🗑️  Eliminando de BASCAR registros que cruzaron con PAGAPL', [
            'step' => self::class,
            'run_id' => $run->id,
        ]);

        // Contar registros antes de la eliminación
        $countBefore = DB::table($tableName)
            ->where('run_id', $run->id)
            ->count();

        Log::info('Registros en BASCAR antes de eliminar', [
            'run_id' => $run->id,
            'count_before' => $countBefore,
        ]);

        // Eliminar registros que tienen composite_key en PAGAPL
        $deleted = DB::delete("
            DELETE FROM {$tableName}
            WHERE run_id = ?
                AND EXISTS (
                    SELECT 1
                    FROM data_source_pagapl p
                    WHERE p.composite_key = {$tableName}.composite_key
                        AND p.run_id = ?
                )
        ", [$run->id, $run->id]);

        // Contar registros después de la eliminación
        $countAfter = DB::table($tableName)
            ->where('run_id', $run->id)
            ->count();

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('✅ Registros eliminados de BASCAR', [
            'run_id' => $run->id,
            'count_before' => $countBefore,
            'deleted' => $deleted,
            'count_after' => $countAfter,
            'duration_ms' => $duration,
        ]);

        // Validar consistencia
        if ($countBefore - $deleted !== $countAfter) {
            Log::warning('⚠️  Inconsistencia en conteo de eliminación', [
                'run_id' => $run->id,
                'count_before' => $countBefore,
                'deleted' => $deleted,
                'count_after' => $countAfter,
                'expected_after' => $countBefore - $deleted,
            ]);
        }

        // Validar que quedaron registros para procesar
        if ($countAfter === 0) {
            Log::warning('⚠️  No quedaron registros en BASCAR después de eliminar cruzados', [
                'run_id' => $run->id,
                'deleted' => $deleted,
            ]);
        }
    }
}
