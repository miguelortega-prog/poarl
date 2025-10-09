<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Agregar consecutivo a BASCAR.
 *
 * Genera un número consecutivo único para cada registro de BASCAR con el formato:
 * CON-{IDENT_ASEGURADO}-{NUM_TOMADOR}-{YYYYMMDD}-{SECUENCIA}
 *
 * Componentes:
 * - CON: Prefijo fijo
 * - IDENT_ASEGURADO: Tipo de identificación del asegurado
 * - NUM_TOMADOR: Número de identificación del tomador
 * - YYYYMMDD: Fecha de ejecución en formato año-mes-día
 * - SECUENCIA: Número secuencial de 5 dígitos (00001, 00002, ...)
 *
 * Ejemplo: CON-NIT-860008645-20251003-00001
 */
final class AddSequenceStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Agregar consecutivo a BASCAR';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        $startTime = microtime(true);

        Log::info('🔢 Generando consecutivos para BASCAR', [
            'step' => self::class,
            'run_id' => $run->id,
        ]);

        // Agregar columna consecutivo si no existe
        $this->ensureConsecutivoColumnExists($run);

        // Generar consecutivos
        $updatedCount = $this->generateConsecutivos($run);

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('✅ Consecutivos generados en BASCAR', [
            'run_id' => $run->id,
            'records_updated' => $updatedCount,
            'duration_ms' => $duration,
        ]);
    }

    /**
     * Asegura que la columna consecutivo exista en data_source_bascar.
     */
    private function ensureConsecutivoColumnExists(CollectionNoticeRun $run): void
    {
        // Verificar si la columna ya existe
        $exists = DB::selectOne("
            SELECT COUNT(*) as count
            FROM information_schema.columns
            WHERE table_name = 'data_source_bascar'
                AND column_name = 'consecutivo'
        ")->count > 0;

        if (!$exists) {
            DB::statement("
                ALTER TABLE data_source_bascar
                ADD COLUMN consecutivo VARCHAR(100) NULL
            ");

            Log::info('Columna consecutivo creada en data_source_bascar', [
                'run_id' => $run->id,
            ]);
        } else {
            Log::debug('Columna consecutivo ya existe en data_source_bascar', [
                'run_id' => $run->id,
            ]);
        }
    }

    /**
     * Genera consecutivos para todos los registros de BASCAR.
     *
     * Formato: CON-{IDENT_ASEGURADO}-{NUM_TOMADOR}-{YYYYMMDD}-{SECUENCIA}
     */
    private function generateConsecutivos(CollectionNoticeRun $run): int
    {
        Log::info('Generando consecutivos con formato CON-IDENT-TOMADOR-FECHA-SECUENCIA', [
            'run_id' => $run->id,
        ]);

        // Usar UPDATE con subconsulta para generar consecutivos con ROW_NUMBER()
        // ROW_NUMBER() genera secuencia 1, 2, 3... ordenado por id
        $updated = DB::update("
            UPDATE data_source_bascar
            SET consecutivo = subquery.consecutivo
            FROM (
                SELECT
                    id,
                    CONCAT(
                        'CON',
                        '-',
                        COALESCE(ident_asegurado, ''),
                        '-',
                        COALESCE(num_tomador, ''),
                        '-',
                        TO_CHAR(NOW(), 'YYYYMMDD'),
                        '-',
                        LPAD(ROW_NUMBER() OVER (ORDER BY id)::TEXT, 5, '0')
                    ) as consecutivo
                FROM data_source_bascar
                WHERE run_id = ?
            ) AS subquery
            WHERE data_source_bascar.id = subquery.id
                AND data_source_bascar.run_id = ?
        ", [$run->id, $run->id]);

        Log::info('✅ Consecutivos generados', [
            'run_id' => $run->id,
            'updated_count' => $updated,
        ]);

        return $updated;
    }
}
