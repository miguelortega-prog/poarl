<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Filtrar registros de DETTRA por tipo_cotizante y riesgo.
 *
 * Reglas de filtrado (SE MANTIENEN estos registros):
 * 1. tipo_cotizante IN ('3', '59') AND riesgo IN ('1', '2', '3')
 * 2. tipo_cotizante = '16' (con cualquier riesgo)
 *
 * Proceso:
 * - Cuenta registros antes del filtro
 * - Elimina registros que NO cumplan las reglas
 * - Reporta estadísticas de eliminación
 *
 * Este step debe ejecutarse DESPUÉS de sanitizar datos y ANTES de cruces con otras tablas.
 */
final class FilterDettraByTipoCotizanteStep implements ProcessingStepInterface
{
    /**
     * Tipos de cotizante válidos para independientes.
     */
    private const TIPO_COTIZANTE_INDEPENDIENTE = ['3', '59'];
    private const TIPO_COTIZANTE_ESPECIAL = '16';

    /**
     * Niveles de riesgo válidos para tipos 3 y 59.
     */
    private const RIESGOS_VALIDOS = ['1', '2', '3'];

    public function getName(): string
    {
        return 'Filtrar DETTRA por tipo_cotizante y riesgo';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Filtrando DETTRA por tipo_cotizante y riesgo', ['run_id' => $run->id]);

        $totalBefore = DB::table('data_source_dettra')->where('run_id', $run->id)->count();

        if ($totalBefore === 0) {
            Log::info('Filtrado DETTRA completado (sin registros)', ['run_id' => $run->id]);
            return;
        }

        $this->deleteInvalidRecords($run);

        Log::info('Filtrado DETTRA completado', ['run_id' => $run->id]);
    }

    /**
     * Elimina registros que NO cumplan las reglas de filtrado.
     *
     * Mantiene registros que cumplan:
     * - tipo_cotizante IN ('3', '59') AND riesgo IN ('1', '2', '3')
     * - O tipo_cotizante = '16' (cualquier riesgo)
     */
    private function deleteInvalidRecords(CollectionNoticeRun $run): void
    {
        DB::delete("
            DELETE FROM data_source_dettra
            WHERE run_id = ?
                AND NOT (
                    -- Regla 1: tipo_cotizante 3 o 59 con riesgo 1, 2 o 3
                    (
                        tipo_cotizante IN (?, ?)
                        AND riesgo IN (?, ?, ?)
                    )
                    -- Regla 2: tipo_cotizante 16 con cualquier riesgo
                    OR tipo_cotizante = ?
                )
        ", [
            $run->id,
            self::TIPO_COTIZANTE_INDEPENDIENTE[0], // '3'
            self::TIPO_COTIZANTE_INDEPENDIENTE[1], // '59'
            self::RIESGOS_VALIDOS[0],               // '1'
            self::RIESGOS_VALIDOS[1],               // '2'
            self::RIESGOS_VALIDOS[2],               // '3'
            self::TIPO_COTIZANTE_ESPECIAL,          // '16'
        ]);
    }
}
