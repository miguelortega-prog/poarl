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
        Log::info('Sanitizando campos numéricos', ['run_id' => $run->id]);

        foreach (self::NUMERIC_FIELDS as $tableName => $columns) {
            $this->sanitizeTableFields($tableName, $columns, $run);
        }

        Log::info('Sanitización de campos numéricos completada', ['run_id' => $run->id]);
    }

    /**
     * Sanitiza campos numéricos de una tabla específica.
     *
     * @param string $tableName Nombre de la tabla
     * @param array<string> $columns Lista de columnas a sanitizar
     * @param CollectionNoticeRun $run Run actual
     */
    private function sanitizeTableFields(string $tableName, array $columns, CollectionNoticeRun $run): void
    {
        $recordCount = DB::table($tableName)->where('run_id', $run->id)->count();

        if ($recordCount === 0) {
            return;
        }

        // Construir SET clause dinámicamente para todas las columnas
        $setClauses = array_map(function ($column) {
            return "{$column} = REPLACE(REPLACE({$column}, '.', ''), ',', '.')";
        }, $columns);

        $setClause = implode(', ', $setClauses);

        // Ejecutar UPDATE masivo
        DB::update("
            UPDATE {$tableName}
            SET {$setClause}
            WHERE run_id = ?
        ", [$run->id]);
    }
}
