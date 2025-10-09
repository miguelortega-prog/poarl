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
        $startTime = microtime(true);

        Log::info('🔍 Identificando PSI en BASCAR desde BAPRPO', [
            'step' => self::class,
            'run_id' => $run->id,
        ]);

        // Paso 1: Crear columna 'psi' en BASCAR si no existe
        $this->ensurePsiColumn();

        // Paso 2: Crear índices en NIT si no existen
        $this->ensureNitIndexes();

        // Paso 3: Actualizar PSI desde BAPRPO
        $this->updatePsiFromBaprpo($run);

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('✅ Identificación de PSI completada', [
            'run_id' => $run->id,
            'duration_ms' => $duration,
        ]);
    }

    /**
     * Asegura que exista la columna 'psi' en data_source_bascar.
     */
    private function ensurePsiColumn(): void
    {
        $tableName = 'data_source_bascar';

        if ($this->columnExists($tableName, 'psi')) {
            Log::debug('Columna psi ya existe en BASCAR', [
                'table' => $tableName,
            ]);
            return;
        }

        Log::info('Creando columna psi en BASCAR', [
            'table' => $tableName,
        ]);

        DB::statement("
            ALTER TABLE {$tableName}
            ADD COLUMN psi VARCHAR(10)
        ");

        // Crear índice para mejorar performance en consultas futuras
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_{$tableName}_psi
            ON {$tableName}(psi)
        ");

        Log::info('✅ Columna psi creada con índice', [
            'table' => $tableName,
        ]);
    }

    /**
     * Asegura que existan índices en las columnas de identificación de ambas tablas.
     */
    private function ensureNitIndexes(): void
    {
        // Índice en BASCAR.NUM_TOMADOR
        if (!$this->indexExists('data_source_bascar', 'idx_data_source_bascar_num_tomador')) {
            Log::info('Creando índice en BASCAR.NUM_TOMADOR');

            DB::statement("
                CREATE INDEX IF NOT EXISTS idx_data_source_bascar_num_tomador
                ON data_source_bascar(num_tomador)
            ");

            Log::info('✅ Índice creado en BASCAR.NUM_TOMADOR');
        }

        // Índice en BAPRPO.tomador
        if (!$this->indexExists('data_source_baprpo', 'idx_data_source_baprpo_tomador')) {
            Log::info('Creando índice en BAPRPO.tomador');

            DB::statement("
                CREATE INDEX IF NOT EXISTS idx_data_source_baprpo_tomador
                ON data_source_baprpo(tomador)
            ");

            Log::info('✅ Índice creado en BAPRPO.tomador');
        }
    }

    /**
     * Actualiza la columna psi en BASCAR con datos de BAPRPO.
     */
    private function updatePsiFromBaprpo(CollectionNoticeRun $run): void
    {
        Log::info('Actualizando PSI desde BAPRPO', [
            'run_id' => $run->id,
        ]);

        // Contar registros antes de actualizar
        $totalBascar = DB::table('data_source_bascar')
            ->where('run_id', $run->id)
            ->count();

        // Actualizar PSI desde BAPRPO
        $updated = DB::update("
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

        // Contar registros con PSI poblado
        $withPsi = DB::table('data_source_bascar')
            ->where('run_id', $run->id)
            ->whereNotNull('psi')
            ->where('psi', '!=', '')
            ->count();

        // Contar registros sin PSI
        $withoutPsi = DB::table('data_source_bascar')
            ->where('run_id', $run->id)
            ->where(function ($query) {
                $query->whereNull('psi')
                      ->orWhere('psi', '');
            })
            ->count();

        Log::info('✅ PSI actualizado desde BAPRPO', [
            'run_id' => $run->id,
            'total_bascar' => $totalBascar,
            'updated' => $updated,
            'with_psi' => $withPsi,
            'without_psi' => $withoutPsi,
            'coverage_pct' => $totalBascar > 0 ? round(($withPsi / $totalBascar) * 100, 2) : 0,
        ]);

        // Warning si muchos registros no tienen PSI
        if ($withoutPsi > 0) {
            $pctWithoutPsi = round(($withoutPsi / $totalBascar) * 100, 2);

            if ($pctWithoutPsi > 50) {
                Log::warning('⚠️  Más del 50% de registros no tienen PSI', [
                    'run_id' => $run->id,
                    'without_psi' => $withoutPsi,
                    'total' => $totalBascar,
                    'percent' => $pctWithoutPsi,
                ]);
            } else {
                Log::info('Registros sin PSI (no cruzaron con BAPRPO)', [
                    'run_id' => $run->id,
                    'without_psi' => $withoutPsi,
                    'percent' => $pctWithoutPsi,
                ]);
            }
        }
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
