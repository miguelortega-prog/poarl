<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Eliminar registros de DETTRA que cruzaron con recaudo.
 *
 * Este step elimina de la tabla DETTRA todos los trabajadores independientes que
 * fueron identificados como que ya realizaron el pago (cruzaron con PAGAPL, PAGLOG o PAGLOG_DV).
 *
 * Estos trabajadores ya fueron exportados al archivo de excluidos en el paso anterior
 * (ExportExcludedDettraRecordsStep), por lo que ahora se eliminan de DETTRA para que
 * los pasos posteriores solo trabajen con trabajadores que SÍ deben recibir el comunicado de mora.
 *
 * Criterio de eliminación:
 * - observacion_trabajadores LIKE '%Cruza con recaudo%'
 *
 * IMPORTANTE: Este step debe ejecutarse DESPUÉS de exportar los excluidos,
 * para asegurar que no se pierde información de auditoría.
 */
final class RemoveCrossedDettraRecordsStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Eliminar registros de DETTRA que cruzaron con recaudo';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Eliminando registros de DETTRA que cruzaron con recaudo', ['run_id' => $run->id]);

        $totalBefore = DB::table('data_source_dettra')
            ->where('run_id', $run->id)
            ->count();

        if ($totalBefore === 0) {
            Log::info('No hay registros en DETTRA para eliminar', ['run_id' => $run->id]);
            return;
        }

        // Eliminar registros que cruzaron con recaudo
        $deleted = DB::delete("
            DELETE FROM data_source_dettra
            WHERE run_id = ?
                AND observacion_trabajadores LIKE ?
        ", [$run->id, '%Cruza con recaudo%']);

        $totalAfter = DB::table('data_source_dettra')
            ->where('run_id', $run->id)
            ->count();

        Log::info('Registros eliminados de DETTRA', [
            'run_id' => $run->id,
            'total_antes' => $totalBefore,
            'total_despues' => $totalAfter,
            'eliminados' => $deleted,
            'porcentaje_eliminado' => $totalBefore > 0 ? round(($deleted / $totalBefore) * 100, 2) : 0,
            'quedan_para_comunicado' => $totalAfter,
        ]);

        if ($totalAfter === 0) {
            Log::warning('DETTRA quedó vacío después de eliminar cruzados - Todos los trabajadores ya pagaron', [
                'run_id' => $run->id,
            ]);
        }
    }
}
