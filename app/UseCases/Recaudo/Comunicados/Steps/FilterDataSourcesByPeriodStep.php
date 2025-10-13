<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Filtrar PAGLOG por periodo del run.
 *
 * Este step optimiza el procesamiento de PAGLOG eliminando registros con periodos
 * diferentes al del run, ya que estos nunca cruzarán en los pasos posteriores.
 *
 * NOTA IMPORTANTE: Solo filtra PAGLOG, NO filtra PAGAPL.
 * - PAGAPL se mantiene completo porque se usará para buscar correos y direcciones
 *   de periodos diferentes cuando no se encuentren en BASACT.
 *
 * Tabla filtrada:
 * - PAGLOG: Filtra por columna 'periodo_pago' (mantiene solo registros del periodo del run)
 *
 * Beneficios:
 * 1. Reduce el volumen de datos de PAGLOG en cruces posteriores
 * 2. Mejora el performance de índices en PAGLOG
 * 3. No afecta el resultado (registros de PAGLOG de otros periodos no cruzarían)
 *
 * IMPORTANTE: Este step debe ejecutarse ANTES de generar composite keys.
 */
final class FilterDataSourcesByPeriodStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Filtrar PAGLOG por periodo del run';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Filtrando PAGLOG por periodo', ['run_id' => $run->id, 'periodo' => $run->period]);

        $period = $run->period;

        // Si el periodo no está definido o es "Todos Los Periodos", no filtrar
        if (empty($period) || strtolower($period) === 'todos los periodos') {
            Log::info('Filtrado de PAGLOG por periodo omitido (sin periodo o todos los periodos)', ['run_id' => $run->id]);
            return;
        }

        // Solo filtrar PAGLOG por periodo_pago (PAGAPL se mantiene completo)
        $deletedPaglog = $this->filterPaglog($run, $period);

        Log::info('Filtrado de PAGLOG por periodo completado', [
            'run_id' => $run->id,
            'periodo' => $period,
            'paglog_eliminados' => $deletedPaglog,
        ]);
    }

    /**
     * Filtra PAGLOG eliminando registros con periodo_pago diferente al del run.
     *
     * @return int Cantidad de registros eliminados
     */
    private function filterPaglog(CollectionNoticeRun $run, string $period): int
    {
        $totalBefore = DB::table('data_source_paglog')
            ->where('run_id', $run->id)
            ->count();

        if ($totalBefore === 0) {
            Log::debug('PAGLOG sin registros, filtrado omitido', ['run_id' => $run->id]);
            return 0;
        }

        // Eliminar registros con periodo_pago diferente
        $deleted = DB::delete("
            DELETE FROM data_source_paglog
            WHERE run_id = ?
                AND (periodo_pago IS NULL OR periodo_pago != ?)
        ", [$run->id, $period]);

        $totalAfter = DB::table('data_source_paglog')
            ->where('run_id', $run->id)
            ->count();

        Log::info('PAGLOG filtrado por periodo', [
            'run_id' => $run->id,
            'periodo' => $period,
            'total_antes' => $totalBefore,
            'total_despues' => $totalAfter,
            'eliminados' => $deleted,
            'porcentaje_conservado' => $totalBefore > 0 ? round(($totalAfter / $totalBefore) * 100, 2) : 0,
        ]);

        return $deleted;
    }
}
