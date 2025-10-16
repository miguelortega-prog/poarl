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
        $tableName = 'data_source_bascar';

        Log::info('Eliminando registros de BASCAR que cruzaron con PAGAPL', ['run_id' => $run->id]);

        DB::delete("
            DELETE FROM {$tableName}
            WHERE run_id = ?
                AND EXISTS (
                    SELECT 1
                    FROM data_source_pagapl p
                    WHERE p.composite_key = {$tableName}.composite_key
                        AND p.run_id = ?
                )
        ", [$run->id, $run->id]);

        Log::info('Registros eliminados de BASCAR', ['run_id' => $run->id]);
    }
}
