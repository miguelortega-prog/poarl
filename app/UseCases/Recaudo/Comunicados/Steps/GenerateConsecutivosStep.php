<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Generar consecutivos únicos para cada comunicado.
 *
 * Este step crea un consecutivo único para cada registro de DETTRA
 * que será usado como identificador del comunicado.
 *
 * Formato del consecutivo:
 * CON-{TIPO_DOC}-{NIT}-{FECHA}-{SERIAL}
 *
 * Componentes:
 * - CON: Prefijo fijo (Comunicado)
 * - TIPO_DOC: Tipo de documento sanitizado (CC, CE, PE, TI)
 * - NIT: Número de identificación del trabajador
 * - FECHA: Fecha de generación en formato YYYYMMDD
 * - SERIAL: Número secuencial de 5 dígitos (00001 hasta 99999)
 *
 * Ejemplo: CON-CC-1024546789-20251003-00001
 *
 * IMPORTANTE: Este step debe ejecutarse DESPUÉS de:
 * - SanitizeTipoDocFieldStep (tipo_doc debe estar normalizado)
 * - Todos los filtrados y eliminaciones (para evitar huecos en seriales)
 */
final class GenerateConsecutivosStep implements ProcessingStepInterface
{
    private const PREFIX = 'CON';
    private const SERIAL_LENGTH = 5;

    public function getName(): string
    {
        return 'Generar consecutivos para comunicados';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Generando consecutivos para comunicados', ['run_id' => $run->id]);

        $totalRecords = DB::table('data_source_dettra')
            ->where('run_id', $run->id)
            ->count();

        if ($totalRecords === 0) {
            Log::info('No hay registros en DETTRA para generar consecutivos', ['run_id' => $run->id]);
            return;
        }

        $fecha = now()->format('Ymd');
        $updated = $this->generateConsecutivos($run, $fecha);

        Log::info('Consecutivos generados', [
            'run_id' => $run->id,
            'total_registros' => $totalRecords,
            'consecutivos_generados' => $updated,
            'fecha_envio' => $fecha,
        ]);

        // Verificar si hay registros sin consecutivo (no deberían existir)
        $sinConsecutivo = DB::table('data_source_dettra')
            ->where('run_id', $run->id)
            ->whereNull('consecutivo')
            ->count();

        if ($sinConsecutivo > 0) {
            Log::warning('Registros sin consecutivo detectados', [
                'run_id' => $run->id,
                'sin_consecutivo' => $sinConsecutivo,
            ]);
        }
    }

    /**
     * Genera consecutivos para todos los registros del run.
     *
     * Utiliza ROW_NUMBER() para generar seriales ascendentes únicos.
     *
     * @param CollectionNoticeRun $run
     * @param string $fecha Fecha en formato YYYYMMDD
     * @return int Cantidad de registros actualizados
     */
    private function generateConsecutivos(CollectionNoticeRun $run, string $fecha): int
    {
        // Usar una subconsulta con ROW_NUMBER() para generar seriales
        // IMPORTANTE: ORDER BY id garantiza que los seriales sean reproducibles
        $affectedRows = DB::update("
            UPDATE data_source_dettra AS dettra
            SET consecutivo = subquery.consecutivo
            FROM (
                SELECT
                    id,
                    CONCAT(
                        ?,
                        '-',
                        COALESCE(tipo_doc, 'NN'),
                        '-',
                        COALESCE(nit, '0'),
                        '-',
                        ?,
                        '-',
                        LPAD(ROW_NUMBER() OVER (ORDER BY id)::text, ?, '0')
                    ) as consecutivo
                FROM data_source_dettra
                WHERE run_id = ?
            ) AS subquery
            WHERE dettra.id = subquery.id
                AND dettra.run_id = ?
                AND dettra.consecutivo IS NULL
        ", [self::PREFIX, $fecha, self::SERIAL_LENGTH, $run->id, $run->id]);

        return $affectedRows;
    }
}
