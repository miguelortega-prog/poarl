<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Generar llave compuesta en DETTRA.
 *
 * Genera el campo 'composite_key' concatenando:
 * - nit (NIT de la empresa)
 * - periodo (periodo del run en formato YYYYMM)
 *
 * Esta llave se usará para cruces con otras tablas (BASACT, PAGLOG, etc.)
 *
 * Operación SQL:
 * UPDATE data_source_dettra
 * SET composite_key = TRIM(nit) || 'PERIODO_RUN'
 * WHERE run_id = X AND nit IS NOT NULL
 */
final class GenerateDettraCompositeKeyStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Generar llaves compuestas en DETTRA';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        $tableName = 'data_source_dettra';

        Log::info('Generando llaves compuestas en DETTRA', ['run_id' => $run->id]);

        if (!$this->columnExists($tableName, 'composite_key')) {
            DB::statement("ALTER TABLE {$tableName} ADD COLUMN composite_key VARCHAR(255)");
            DB::statement("CREATE INDEX IF NOT EXISTS idx_{$tableName}_composite_key ON {$tableName}(composite_key)");
        }

        DB::update("
            UPDATE {$tableName}
            SET composite_key = TRIM(nit) || ?
            WHERE run_id = ?
                AND nit IS NOT NULL
                AND nit != ''
        ", [$run->period, $run->id]);

        $totalRows = DB::table($tableName)->where('run_id', $run->id)->count();
        $keysGenerated = DB::table($tableName)
            ->where('run_id', $run->id)
            ->whereNotNull('composite_key')
            ->where('composite_key', '!=', '')
            ->count();

        if ($keysGenerated === 0 && $totalRows > 0) {
            throw new \RuntimeException(
                "No se generaron llaves compuestas en DETTRA. Verifica que la columna NIT no esté vacía."
            );
        }

        Log::info('Llaves compuestas generadas en DETTRA', ['run_id' => $run->id]);
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
