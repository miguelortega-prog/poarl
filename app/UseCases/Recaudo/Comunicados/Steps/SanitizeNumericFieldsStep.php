<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Sanitizar campos numéricos en tablas de data sources.
 *
 * Limpia campos numéricos que vienen con formato europeo (separador de miles: punto, decimal: coma)
 * y los convierte al formato estándar para PostgreSQL (sin separador de miles, decimal: punto).
 *
 * Transformación:
 * - Entrada: "1.234.567,89" (formato europeo)
 * - Salida: "1234567.89" (formato estándar)
 *
 * Proceso:
 * 1. REPLACE(campo, '.', '') → Elimina puntos (separador de miles)
 * 2. REPLACE(resultado, ',', '.') → Convierte coma a punto (separador decimal)
 *
 * Este step debe ejecutarse DESPUÉS de cargar los datos y ANTES de cualquier transformación SQL
 * que requiera usar estos campos numéricos en cálculos.
 */
final class SanitizeNumericFieldsStep implements ProcessingStepInterface
{
    /**
     * Mapeo de tablas y sus columnas numéricas que requieren sanitización.
     *
     * Formato: 'tabla' => ['columna1', 'columna2', ...]
     */
    private const NUMERIC_FIELDS = [
        'data_source_bascar' => ['valor_total_fact'],
    ];

    public function getName(): string
    {
        return 'Sanitizar campos numéricos (formato europeo → estándar)';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        $startTime = microtime(true);

        Log::info('🧹 Sanitizando campos numéricos en data sources', [
            'step' => self::class,
            'run_id' => $run->id,
        ]);

        $totalFieldsSanitized = 0;

        foreach (self::NUMERIC_FIELDS as $tableName => $columns) {
            $sanitizedInTable = $this->sanitizeTableFields($tableName, $columns, $run);
            $totalFieldsSanitized += $sanitizedInTable;
        }

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('✅ Sanitización de campos numéricos completada', [
            'run_id' => $run->id,
            'total_fields_sanitized' => $totalFieldsSanitized,
            'duration_ms' => $duration,
        ]);
    }

    /**
     * Sanitiza campos numéricos de una tabla específica.
     *
     * @param string $tableName Nombre de la tabla
     * @param array<string> $columns Lista de columnas a sanitizar
     * @param CollectionNoticeRun $run Run actual
     * @return int Número de campos sanitizados
     */
    private function sanitizeTableFields(string $tableName, array $columns, CollectionNoticeRun $run): int
    {
        Log::info('Sanitizando campos numéricos en tabla', [
            'table' => $tableName,
            'columns' => $columns,
            'run_id' => $run->id,
        ]);

        // Contar registros en la tabla antes de sanitizar
        $recordCount = DB::table($tableName)
            ->where('run_id', $run->id)
            ->count();

        if ($recordCount === 0) {
            Log::warning('Tabla sin registros, skipping sanitización', [
                'table' => $tableName,
                'run_id' => $run->id,
            ]);
            return 0;
        }

        // Construir SET clause dinámicamente para todas las columnas
        $setClauses = array_map(function ($column) {
            // REPLACE(REPLACE(columna, '.', ''), ',', '.')
            // Primero quita puntos (separador de miles), luego convierte coma a punto (decimal)
            return "{$column} = REPLACE(REPLACE({$column}, '.', ''), ',', '.')";
        }, $columns);

        $setClause = implode(', ', $setClauses);

        // Ejecutar UPDATE masivo
        $updated = DB::update("
            UPDATE {$tableName}
            SET {$setClause}
            WHERE run_id = ?
        ", [$run->id]);

        Log::info('✅ Campos numéricos sanitizados en tabla', [
            'table' => $tableName,
            'run_id' => $run->id,
            'records_in_table' => number_format($recordCount),
            'records_updated' => number_format($updated),
            'columns_sanitized' => $columns,
        ]);

        return count($columns);
    }
}
