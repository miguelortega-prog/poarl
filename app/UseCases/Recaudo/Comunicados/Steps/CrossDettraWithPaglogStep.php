<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Cruzar DETTRA con PAGLOG (sin dígito de verificación).
 *
 * Este step realiza un cruce entre:
 * - DETTRA.composite_key = NIT + periodo del run
 * - PAGLOG.nit_periodo = NIT_EMPRESA + PERIODO_PAGO (sin DV)
 *
 * Proceso:
 * 1. Busca registros de DETTRA cuya composite_key existe en PAGLOG.nit_periodo
 * 2. Si cruza:
 *    - Marca DETTRA.cruce_paglog = nit_periodo de PAGLOG encontrada
 *    - Actualiza/concatena DETTRA.observacion_trabajadores = "Cruza con recaudo"
 *
 * Este cruce permite identificar trabajadores independientes que ya realizaron
 * el pago correspondiente según el log bancario, por lo que NO deben recibir
 * comunicado de mora.
 */
final class CrossDettraWithPaglogStep implements ProcessingStepInterface
{
    private const OBSERVACION = 'Cruza con recaudo';

    public function getName(): string
    {
        return 'Cruzar DETTRA con PAGLOG (sin DV)';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Iniciando cruce DETTRA con PAGLOG (sin DV)', ['run_id' => $run->id]);

        $totalBefore = DB::table('data_source_dettra')
            ->where('run_id', $run->id)
            ->count();

        if ($totalBefore === 0) {
            Log::info('Cruce DETTRA-PAGLOG omitido (sin registros en DETTRA)', ['run_id' => $run->id]);
            return;
        }

        $crossed = $this->crossDettraWithPaglog($run);

        Log::info('Cruce DETTRA-PAGLOG (sin DV) completado', [
            'run_id' => $run->id,
            'total_dettra' => $totalBefore,
            'cruzados' => $crossed,
            'porcentaje_cruzado' => $totalBefore > 0 ? round(($crossed / $totalBefore) * 100, 2) : 0,
        ]);
    }

    /**
     * Realiza el cruce entre DETTRA y PAGLOG usando composite_key y nit_periodo.
     *
     * Actualiza en DETTRA:
     * - cruce_paglog = nit_periodo de PAGLOG
     * - observacion_trabajadores = "Cruza con recaudo" (concatena si ya existe)
     *
     * @return int Cantidad de registros cruzados
     */
    private function crossDettraWithPaglog(CollectionNoticeRun $run): int
    {
        // Actualizar DETTRA con datos del cruce
        $affectedRows = DB::update("
            UPDATE data_source_dettra AS dettra
            SET
                cruce_paglog = paglog.nit_periodo,
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
                AND dettra.composite_key = paglog.nit_periodo
                AND dettra.cruce_paglog IS NULL
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
