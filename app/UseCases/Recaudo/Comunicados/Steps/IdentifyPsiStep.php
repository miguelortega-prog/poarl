<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Identificar PSI (Póliza de Seguro Independiente).
 *
 * Cruza BASCAR con BAPRPO para identificar si el aportante tiene póliza independiente:
 * 1. Crea índices en NUM_TOMADOR (BASCAR) y tomador (BAPRPO) si no existen
 * 2. Actualiza BASCAR.psi con BAPRPO.pol_independiente donde coinciden
 *
 * Nota: La columna 'psi' y su índice ya fueron creados por CreateBascarIndexesStep (paso 2)
 *
 * Mapeo de columnas (validado con cliente):
 * - BASCAR: Campo NIT -> NUM_TOMADOR
 * - BAPRPO: Campo NIT -> tomador
 * - BAPRPO: Campo PSI -> pol_independiente
 *
 * Cruce SQL:
 * UPDATE data_source_bascar b
 * SET psi = baprpo.pol_independiente
 * FROM data_source_baprpo baprpo
 * WHERE b.NUM_TOMADOR = baprpo.tomador
 *   AND b.run_id = X
 *   AND baprpo.run_id = X
 *   AND b.NUM_TOMADOR IS NOT NULL
 *   AND baprpo.tomador IS NOT NULL
 */
final class IdentifyPsiStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Identificar PSI (Póliza de Seguro Independiente)';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Identificando PSI en BASCAR desde BAPRPO', ['run_id' => $run->id]);

        // Nota: La columna psi y su índice ya fueron creados por CreateBascarIndexesStep (paso 2)
        $this->ensureNitIndexes();
        $this->updatePsiFromBaprpo($run);

        Log::info('Identificación de PSI completada', ['run_id' => $run->id]);
    }

    /**
     * Asegura que existan índices en las columnas de identificación de ambas tablas.
     */
    private function ensureNitIndexes(): void
    {
        if (!$this->indexExists('data_source_bascar', 'idx_data_source_bascar_num_tomador')) {
            DB::statement("
                CREATE INDEX IF NOT EXISTS idx_data_source_bascar_num_tomador
                ON data_source_bascar(num_tomador)
            ");
        }

        if (!$this->indexExists('data_source_baprpo', 'idx_data_source_baprpo_tomador')) {
            DB::statement("
                CREATE INDEX IF NOT EXISTS idx_data_source_baprpo_tomador
                ON data_source_baprpo(tomador)
            ");
        }
    }

    /**
     * Actualiza la columna psi en BASCAR con datos de BAPRPO.
     */
    private function updatePsiFromBaprpo(CollectionNoticeRun $run): void
    {
        DB::update("
            UPDATE data_source_bascar b
            SET psi = TRIM(baprpo.pol_independiente)
            FROM data_source_baprpo baprpo
            WHERE b.num_tomador = baprpo.tomador
                AND b.run_id = ?
                AND baprpo.run_id = ?
                AND b.num_tomador IS NOT NULL
                AND b.num_tomador != ''
                AND baprpo.tomador IS NOT NULL
                AND baprpo.tomador != ''
        ", [$run->id, $run->id]);
    }

    /**
     * Verifica si un índice existe en una tabla.
     */
    private function indexExists(string $tableName, string $indexName): bool
    {
        $result = DB::select("
            SELECT indexname
            FROM pg_indexes
            WHERE tablename = ?
            AND indexname = ?
        ", [$tableName, $indexName]);

        return count($result) > 0;
    }
}
