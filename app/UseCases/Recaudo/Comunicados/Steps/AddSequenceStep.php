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
        Log::info('Generando consecutivos', ['run_id' => $run->id]);

        $this->ensureConsecutivoColumnExists($run);
        $this->generateConsecutivos($run);

        Log::info('Consecutivos generados', ['run_id' => $run->id]);
    }

    /**
     * Asegura que la columna consecutivo exista en data_source_bascar.
     */
    private function ensureConsecutivoColumnExists(CollectionNoticeRun $run): void
    {
        $exists = DB::selectOne("
            SELECT COUNT(*) as count
            FROM information_schema.columns
            WHERE table_name = 'data_source_bascar'
                AND column_name = 'consecutivo'
        ")->count > 0;

        if (!$exists) {
            DB::statement("ALTER TABLE data_source_bascar ADD COLUMN consecutivo VARCHAR(100) NULL");
        }
    }

    /**
     * Genera consecutivos para todos los registros de BASCAR.
     *
     * Formato: CON-{IDENT_ASEGURADO}-{NUM_TOMADOR}-{YYYYMMDD}-{SECUENCIA}
     */
    private function generateConsecutivos(CollectionNoticeRun $run): void
    {
        DB::update("
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
    }
}
