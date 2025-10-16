<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Generar llave compuesta en PAGAPL.
 *
 * Genera el campo 'composite_key' concatenando:
 * - identifi (NIT/CC del aportante)
 * - periodo (periodo del pago en formato YYYYMM)
 *
 * Esta llave se usará para cruzar con BASCAR y determinar qué pagos
 * ya fueron aplicados (y por tanto excluir esos aportantes del comunicado).
 *
 * Operación SQL:
 * UPDATE data_source_pagapl
 * SET composite_key = TRIM(identifi) || periodo
 * WHERE run_id = X AND identifi IS NOT NULL AND periodo IS NOT NULL
 */
final class GeneratePagaplCompositeKeyStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Generar llaves compuestas en PAGAPL';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        $tableName = 'data_source_pagapl';

        Log::info('Generando llaves compuestas en PAGAPL', ['run_id' => $run->id]);

        if (!$this->columnExists($tableName, 'composite_key')) {
            DB::statement("ALTER TABLE {$tableName} ADD COLUMN composite_key VARCHAR(255)");
            DB::statement("CREATE INDEX IF NOT EXISTS idx_{$tableName}_composite_key ON {$tableName}(composite_key)");
        }

        DB::update("
            UPDATE {$tableName}
            SET composite_key = TRIM(identifi) || periodo
            WHERE run_id = ?
                AND identifi IS NOT NULL
                AND identifi != ''
                AND periodo IS NOT NULL
                AND periodo != ''
        ", [$run->id]);

        $keysGenerated = DB::table($tableName)
            ->where('run_id', $run->id)
            ->whereNotNull('composite_key')
            ->where('composite_key', '!=', '')
            ->count();

        if ($keysGenerated === 0) {
            throw new \RuntimeException(
                "No se generaron llaves compuestas en PAGAPL. Verifica que identifi y periodo no estén vacíos."
            );
        }

        Log::info('Llaves compuestas generadas en PAGAPL', ['run_id' => $run->id]);
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
