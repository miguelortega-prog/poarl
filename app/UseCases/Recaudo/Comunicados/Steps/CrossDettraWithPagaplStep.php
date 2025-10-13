<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Cruzar DETTRA con PAGAPL para identificar trabajadores que ya pagaron.
 *
 * Este step realiza un cruce entre las composite_keys de DETTRA y PAGAPL:
 * - DETTRA.composite_key = NIT + periodo del run
 * - PAGAPL.composite_key = Identifi + Periodo (de la tabla PAGAPL)
 *
 * Proceso:
 * 1. Busca registros de DETTRA cuya composite_key existe en PAGAPL
 * 2. Si cruza:
 *    - Marca DETTRA.cruce_pagapl = composite_key de PAGAPL encontrada
 *    - Actualiza DETTRA.observacion_trabajadores = "Cruza con recaudo"
 *
 * Este cruce permite identificar trabajadores independientes que ya realizaron
 * el pago correspondiente, por lo que NO deben recibir comunicado de mora.
 */
final class CrossDettraWithPagaplStep implements ProcessingStepInterface
{
    private const OBSERVACION = 'Cruza con recaudo';

    public function getName(): string
    {
        return 'Cruzar DETTRA con PAGAPL (identificar pagos realizados)';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Iniciando cruce DETTRA con PAGAPL', ['run_id' => $run->id]);

        $totalBefore = DB::table('data_source_dettra')
            ->where('run_id', $run->id)
            ->count();

        if ($totalBefore === 0) {
            Log::info('Cruce DETTRA-PAGAPL omitido (sin registros en DETTRA)', ['run_id' => $run->id]);
            return;
        }

        $crossed = $this->crossDettraWithPagapl($run);

        Log::info('Cruce DETTRA-PAGAPL completado', [
            'run_id' => $run->id,
            'total_dettra' => $totalBefore,
            'cruzados' => $crossed,
            'porcentaje_cruzado' => $totalBefore > 0 ? round(($crossed / $totalBefore) * 100, 2) : 0,
        ]);
    }

    /**
     * Realiza el cruce entre DETTRA y PAGAPL usando composite_keys.
     *
     * Actualiza en DETTRA:
     * - cruce_pagapl = composite_key de PAGAPL
     * - observacion_trabajadores = "Cruza con recaudo"
     *
     * @return int Cantidad de registros cruzados
     */
    private function crossDettraWithPagapl(CollectionNoticeRun $run): int
    {
        // Actualizar DETTRA con datos del cruce
        $affectedRows = DB::update("
            UPDATE data_source_dettra AS dettra
            SET
                cruce_pagapl = pagapl.composite_key,
                observacion_trabajadores = CASE
                    WHEN observacion_trabajadores IS NULL OR observacion_trabajadores = ''
                    THEN ?
                    WHEN observacion_trabajadores LIKE ?
                    THEN observacion_trabajadores
                    ELSE observacion_trabajadores || '; ' || ?
                END
            FROM data_source_pagapl AS pagapl
            WHERE dettra.run_id = ?
                AND pagapl.run_id = ?
                AND dettra.composite_key = pagapl.composite_key
                AND dettra.cruce_pagapl IS NULL
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
