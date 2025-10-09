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
        $tableName = 'data_source_bascar';

        Log::info('Generando llaves compuestas en BASCAR', ['run_id' => $run->id]);

        // Crear columna composite_key si no existe
        if (!$this->columnExists($tableName, 'composite_key')) {
            DB::statement("ALTER TABLE {$tableName} ADD COLUMN composite_key VARCHAR(255)");
            DB::statement("CREATE INDEX IF NOT EXISTS idx_{$tableName}_composite_key ON {$tableName}(composite_key)");
        }

        // Generar composite_key = TRIM(num_tomador) || periodo
        DB::update("
            UPDATE {$tableName}
            SET composite_key = TRIM(num_tomador) || periodo
            WHERE run_id = ?
                AND num_tomador IS NOT NULL
                AND num_tomador != ''
                AND periodo IS NOT NULL
                AND periodo != ''
        ", [$run->id]);

        // Validar que se generaron llaves
        $keysGenerated = DB::table($tableName)
            ->where('run_id', $run->id)
            ->whereNotNull('composite_key')
            ->where('composite_key', '!=', '')
            ->count();

        if ($keysGenerated === 0) {
            throw new \RuntimeException(
                "No se generaron llaves compuestas en BASCAR. Verifica que NUM_TOMADOR y periodo no estén vacíos."
            );
        }

        Log::info('Llaves compuestas generadas en BASCAR', ['run_id' => $run->id]);
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
