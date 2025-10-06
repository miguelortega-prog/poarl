<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Generar llave compuesta en BASCAR.
 *
 * Genera el campo 'composite_key' concatenando:
 * - NUM_TOMADOR (identificador del aportante)
 * - periodo (periodo de la obligación en formato YYYYMM)
 *
 * Esta llave se usará para cruces con otras tablas (PAGAPL, BAPRPO, etc.)
 *
 * Operación SQL:
 * UPDATE data_source_bascar
 * SET composite_key = TRIM(num_tomador) || periodo
 * WHERE run_id = X AND num_tomador IS NOT NULL AND periodo IS NOT NULL
 */
final class GenerateBascarCompositeKeyStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Generar llaves compuestas en BASCAR';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        $startTime = microtime(true);
        $tableName = 'data_source_bascar';

        Log::info('🔑 Generando llaves compuestas en BASCAR', [
            'step' => self::class,
            'run_id' => $run->id,
            'period' => $run->period,
        ]);

        // Verificar que existe la columna composite_key
        if (!$this->columnExists($tableName, 'composite_key')) {
            Log::info('Creando columna composite_key en BASCAR', [
                'run_id' => $run->id,
                'table' => $tableName,
            ]);

            DB::statement("
                ALTER TABLE {$tableName}
                ADD COLUMN composite_key VARCHAR(255)
            ");

            // Crear índice para mejorar performance en cruces
            DB::statement("
                CREATE INDEX IF NOT EXISTS idx_{$tableName}_composite_key
                ON {$tableName}(composite_key)
            ");

            Log::info('✅ Columna composite_key creada con índice', [
                'run_id' => $run->id,
                'table' => $tableName,
            ]);
        }

        // Generar composite_key = TRIM(num_tomador) || periodo
        $updated = DB::update("
            UPDATE {$tableName}
            SET composite_key = TRIM(num_tomador) || periodo
            WHERE run_id = ?
                AND num_tomador IS NOT NULL
                AND num_tomador != ''
                AND periodo IS NOT NULL
                AND periodo != ''
        ", [$run->id]);

        // Contar resultados
        $totalRows = DB::table($tableName)
            ->where('run_id', $run->id)
            ->count();

        $keysGenerated = DB::table($tableName)
            ->where('run_id', $run->id)
            ->whereNotNull('composite_key')
            ->where('composite_key', '!=', '')
            ->count();

        $missingNumTomador = DB::table($tableName)
            ->where('run_id', $run->id)
            ->where(function ($query) {
                $query->whereNull('num_tomador')
                      ->orWhere('num_tomador', '');
            })
            ->count();

        $missingPeriodo = DB::table($tableName)
            ->where('run_id', $run->id)
            ->where(function ($query) {
                $query->whereNull('periodo')
                      ->orWhere('periodo', '');
            })
            ->count();

        // Logs de resultados
        if ($missingNumTomador > 0) {
            Log::warning('⚠️  Algunas filas no tienen NUM_TOMADOR', [
                'run_id' => $run->id,
                'missing_num_tomador' => $missingNumTomador,
            ]);
        }

        if ($missingPeriodo > 0) {
            Log::warning('⚠️  Algunas filas no tienen periodo', [
                'run_id' => $run->id,
                'missing_periodo' => $missingPeriodo,
            ]);
        }

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('✅ Llaves compuestas generadas en BASCAR', [
            'run_id' => $run->id,
            'total_rows' => $totalRows,
            'keys_generated' => $keysGenerated,
            'missing_num_tomador' => $missingNumTomador,
            'missing_periodo' => $missingPeriodo,
            'coverage_pct' => $totalRows > 0 ? round(($keysGenerated / $totalRows) * 100, 2) : 0,
            'duration_ms' => $duration,
        ]);

        // Validar que al menos se generaron algunas llaves
        if ($keysGenerated === 0) {
            throw new \RuntimeException(
                "No se generaron llaves compuestas en BASCAR. " .
                "Verifica que NUM_TOMADOR y periodo no estén vacíos."
            );
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
}
