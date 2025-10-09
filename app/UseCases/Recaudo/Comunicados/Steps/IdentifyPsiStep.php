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
 * 1. Crea columna 'psi' en data_source_bascar
 * 2. Crea índices en NUM_TOMADOR (BASCAR) y tomador (BAPRPO)
 * 3. Actualiza BASCAR.psi con BAPRPO.pol_independiente donde coinciden
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

        $this->ensurePsiColumn();
        $this->ensureNitIndexes();
        $this->updatePsiFromBaprpo($run);

        Log::info('Identificación de PSI completada', ['run_id' => $run->id]);
    }

    /**
     * Asegura que exista la columna 'psi' en data_source_bascar.
     */
    private function ensurePsiColumn(): void
    {
        $tableName = 'data_source_bascar';

        if ($this->columnExists($tableName, 'psi')) {
            return;
        }

        DB::statement("
            ALTER TABLE {$tableName}
            ADD COLUMN psi VARCHAR(10)
        ");

        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_{$tableName}_psi
            ON {$tableName}(psi)
        ");
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
     * Verifica si una columna existe en una tabla.
     */
    private function columnExists(string $tableName, string $columnName): bool
    {
        $result = DB::select("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_name = ?
            AND column_name = ?
        ", [$tableName, $columnName]);

        return count($result) > 0;
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
