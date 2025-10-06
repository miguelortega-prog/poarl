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
 * - identificacion (NIT/CC del aportante)
 * - periodo (periodo del pago en formato YYYYMM)
 *
 * Esta llave se usará para cruzar con BASCAR y determinar qué pagos
 * ya fueron aplicados (y por tanto excluir esos aportantes del comunicado).
 *
 * Operación SQL:
 * UPDATE data_source_pagapl
 * SET composite_key = TRIM(identificacion) || periodo
 * WHERE run_id = X AND identificacion IS NOT NULL AND periodo IS NOT NULL
 */
final class GeneratePagaplCompositeKeyStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Generar llaves compuestas en PAGAPL';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        $startTime = microtime(true);
        $tableName = 'data_source_pagapl';

        Log::info('🔑 Generando llaves compuestas en PAGAPL', [
            'step' => self::class,
            'run_id' => $run->id,
            'period' => $run->period,
        ]);

        // Verificar que existe la columna composite_key
        if (!$this->columnExists($tableName, 'composite_key')) {
            Log::info('Creando columna composite_key en PAGAPL', [
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

        // Generar composite_key = TRIM(identificacion) || periodo
        $updated = DB::update("
            UPDATE {$tableName}
            SET composite_key = TRIM(identificacion) || periodo
            WHERE run_id = ?
                AND identificacion IS NOT NULL
                AND identificacion != ''
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

        $missingIdentificacion = DB::table($tableName)
            ->where('run_id', $run->id)
            ->where(function ($query) {
                $query->whereNull('identificacion')
                      ->orWhere('identificacion', '');
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
        if ($missingIdentificacion > 0) {
            Log::warning('⚠️  Algunas filas no tienen identificacion', [
                'run_id' => $run->id,
                'missing_identificacion' => $missingIdentificacion,
            ]);
        }

        if ($missingPeriodo > 0) {
            Log::warning('⚠️  Algunas filas no tienen periodo', [
                'run_id' => $run->id,
                'missing_periodo' => $missingPeriodo,
            ]);
        }

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('✅ Llaves compuestas generadas en PAGAPL', [
            'run_id' => $run->id,
            'total_rows' => $totalRows,
            'keys_generated' => $keysGenerated,
            'missing_identificacion' => $missingIdentificacion,
            'missing_periodo' => $missingPeriodo,
            'coverage_pct' => $totalRows > 0 ? round(($keysGenerated / $totalRows) * 100, 2) : 0,
            'duration_ms' => $duration,
        ]);

        // Validar que al menos se generaron algunas llaves
        if ($keysGenerated === 0) {
            throw new \RuntimeException(
                "No se generaron llaves compuestas en PAGAPL. " .
                "Verifica que identificacion y periodo no estén vacíos."
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
