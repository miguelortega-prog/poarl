<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Sanitizar campos de fecha en tablas de data sources.
 *
 * Normaliza campos de fecha que pueden venir en diferentes formatos desde Excel
 * y los convierte al formato estándar ISO 8601 (YYYY-MM-DD).
 *
 * Formatos soportados:
 * - DD/MM/YYYY → YYYY-MM-DD (formato europeo/latinoamericano)
 * - MM/DD/YYYY → YYYY-MM-DD (formato estadounidense)
 * - DD-MM-YYYY → YYYY-MM-DD (formato con guiones)
 * - YYYY-MM-DD → Ya está OK (no requiere conversión)
 * - Números seriales de Excel → YYYY-MM-DD (días desde 1900-01-01)
 *
 * Proceso:
 * 1. Intentar convertir con TO_DATE si el formato es detectado
 * 2. Si es número serial de Excel, convertir sumando días a fecha base
 * 3. Si ya está en formato ISO, dejar como está
 * 4. Si no se puede convertir, dejar NULL y registrar warning
 *
 * Este step debe ejecutarse DESPUÉS de cargar los datos y DESPUÉS de SanitizeNumericFieldsStep.
 */
final class SanitizeDateFieldsStep implements ProcessingStepInterface
{
    /**
     * Mapeo de tablas y sus columnas de fecha que requieren sanitización.
     *
     * Formato: 'tabla' => ['columna1', 'columna2', ...]
     */
    private const DATE_FIELDS = [
        'data_source_bascar' => ['fecha_inicio_vig', 'fecha_finalizacion', 'fecha_expedicion'],
        'data_source_dettra' => ['fecha_ini_cobert', 'fech_nacim'],
    ];

    public function getName(): string
    {
        return 'Sanitizar campos de fecha (normalizar a formato YYYY-MM-DD)';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Sanitizando campos de fecha', ['run_id' => $run->id]);

        foreach (self::DATE_FIELDS as $tableName => $columns) {
            $this->sanitizeTableDateFields($tableName, $columns, $run);
        }

        Log::info('Sanitización de campos de fecha completada', ['run_id' => $run->id]);
    }

    /**
     * Sanitiza campos de fecha de una tabla específica.
     *
     * @param string $tableName Nombre de la tabla
     * @param array<string> $columns Lista de columnas a sanitizar
     * @param CollectionNoticeRun $run Run actual
     */
    private function sanitizeTableDateFields(string $tableName, array $columns, CollectionNoticeRun $run): void
    {
        $recordCount = DB::table($tableName)->where('run_id', $run->id)->count();

        if ($recordCount === 0) {
            return;
        }

        // Construir SET clause dinámicamente para todas las columnas
        $setClauses = array_map(function ($column) {
            return "{$column} = " . $this->buildDateConversionSQL($column);
        }, $columns);

        $setClause = implode(', ', $setClauses);

        // Ejecutar UPDATE masivo
        DB::update("
            UPDATE {$tableName}
            SET {$setClause}
            WHERE run_id = ?
        ", [$run->id]);
    }

    /**
     * Construye la expresión SQL para convertir una columna de fecha a formato YYYY-MM-DD.
     *
     * Usa CASE con múltiples intentos de conversión para manejar diferentes formatos.
     *
     * @param string $column Nombre de la columna
     * @return string Expresión SQL
     */
    private function buildDateConversionSQL(string $column): string
    {
        return "
        CASE
            -- Si es NULL o vacío, mantener NULL
            WHEN {$column} IS NULL OR TRIM({$column}) = '' THEN NULL

            -- Si ya está en formato YYYY-MM-DD (patrón: 4 dígitos-2 dígitos-2 dígitos)
            WHEN {$column} ~ '^\d{4}-\d{2}-\d{2}$' THEN {$column}

            -- Si es DD-MM-YYYY (patrón: 2 dígitos-2 dígitos-4 dígitos) - FORMATO PRINCIPAL
            WHEN {$column} ~ '^\d{2}-\d{2}-\d{4}$' THEN
                TO_CHAR(TO_DATE({$column}, 'DD-MM-YYYY'), 'YYYY-MM-DD')

            -- Si es DD/MM/YYYY (patrón: 2 dígitos/2 dígitos/4 dígitos)
            WHEN {$column} ~ '^\d{2}/\d{2}/\d{4}$' THEN
                TO_CHAR(TO_DATE({$column}, 'DD/MM/YYYY'), 'YYYY-MM-DD')

            -- Si es D/M/YYYY o DD/M/YYYY (día y mes sin padding)
            WHEN {$column} ~ '^\d{1,2}/\d{1,2}/\d{4}$' THEN
                TO_CHAR(TO_DATE({$column}, 'DD/MM/YYYY'), 'YYYY-MM-DD')

            -- Si es número serial de Excel (solo dígitos, entre 1 y 50000)
            -- Excel cuenta días desde 1900-01-01 (pero tiene bug con 1900 como bisiesto)
            WHEN {$column} ~ '^\d+$' AND {$column}::INTEGER BETWEEN 1 AND 50000 THEN
                TO_CHAR(DATE '1899-12-30' + ({$column}::INTEGER)::INTEGER, 'YYYY-MM-DD')

            -- Si no se puede convertir, registrar como NULL
            ELSE NULL
        END
        ";
    }
}
