<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Cruzar DETTRA con PAGLOG (con dígito de verificación).
 *
 * Este step realiza un cruce entre:
 * - DETTRA.composite_key = NIT + periodo del run
 * - PAGLOG.composite_key_dv = NIT_EMPRESA_con_DV + PERIODO_PAGO
 *
 * Proceso:
 * 1. Busca registros de DETTRA cuya composite_key existe en PAGLOG.composite_key_dv
 * 2. Si cruza:
 *    - Marca DETTRA.cruce_paglog_dv = composite_key_dv de PAGLOG encontrada
 *    - Actualiza/concatena DETTRA.observacion_trabajadores = "Cruza con recaudo"
 *
 * Este es el segundo tipo de cruce con PAGLOG, usando NIT con dígito de verificación.
 * Algunos sistemas almacenan el NIT con DV concatenado, por lo que este cruce adicional
 * permite identificar más registros que ya pagaron.
 *
 * IMPORTANTE: Este cruce es independiente del anterior (CrossDettraWithPaglogStep).
 * Un mismo registro puede cruzar en ambos, y eso está bien. Lo importante es que si
 * cruza en al menos uno de los 3 cruces (PAGAPL, PAGLOG, PAGLOG_DV), se marca como
 * "Cruza con recaudo".
 */
final class CrossDettraWithPaglogDvStep implements ProcessingStepInterface
{
    private const OBSERVACION = 'Cruza con recaudo';

    public function getName(): string
    {
        return 'Cruzar DETTRA con PAGLOG (con DV)';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Iniciando cruce DETTRA con PAGLOG (con DV)', ['run_id' => $run->id]);

        $totalBefore = DB::table('data_source_dettra')
            ->where('run_id', $run->id)
            ->count();

        if ($totalBefore === 0) {
            Log::info('Cruce DETTRA-PAGLOG_DV omitido (sin registros en DETTRA)', ['run_id' => $run->id]);
            return;
        }

        $crossed = $this->crossDettraWithPaglogDv($run);

        Log::info('Cruce DETTRA-PAGLOG (con DV) completado', [
            'run_id' => $run->id,
            'total_dettra' => $totalBefore,
            'cruzados' => $crossed,
            'porcentaje_cruzado' => $totalBefore > 0 ? round(($crossed / $totalBefore) * 100, 2) : 0,
        ]);
    }

    /**
     * Realiza el cruce entre DETTRA y PAGLOG usando composite_key y composite_key_dv.
     *
     * Actualiza en DETTRA:
     * - cruce_paglog_dv = composite_key_dv de PAGLOG
     * - observacion_trabajadores = "Cruza con recaudo" (concatena si ya existe)
     *
     * @return int Cantidad de registros cruzados
     */
    private function crossDettraWithPaglogDv(CollectionNoticeRun $run): int
    {
        // Actualizar DETTRA con datos del cruce
        $affectedRows = DB::update("
            UPDATE data_source_dettra AS dettra
            SET
                cruce_paglog_dv = paglog.composite_key_dv,
                observacion_trabajadores = CASE
                    WHEN observacion_trabajadores IS NULL OR observacion_trabajadores = ''
                    THEN ?
                    WHEN observacion_trabajadores LIKE ?
                    THEN observacion_trabajadores
                    ELSE observacion_trabajadores || '; ' || ?
                END
            FROM data_source_paglog AS paglog
            WHERE dettra.run_id = ?
                AND paglog.run_id = ?
                AND dettra.composite_key = paglog.composite_key_dv
                AND dettra.cruce_paglog_dv IS NULL
        ", [
            self::OBSERVACION,
            '%' . self::OBSERVACION . '%',
            self::OBSERVACION,
            $run->id,
            $run->id,
        ]);

        return $affectedRows;
    }
}
