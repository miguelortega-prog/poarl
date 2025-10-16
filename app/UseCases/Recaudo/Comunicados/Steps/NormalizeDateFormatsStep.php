<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Normalizar formatos de fechas desde Excel.
 *
 * PROBLEMA: La librería excelize de Go formatea automáticamente las fechas de Excel a formato MM-DD-YY.
 * - Excel guarda fechas como números seriales
 * - excelize las convierte a texto pero usa formato estadounidense con año corto
 * - Ejemplo: 6/01/2024 → 09-01-25
 *
 * SOLUCIÓN: Este step convierte el formato de vuelta a D/M/YYYY.
 *
 * Conversiones soportadas:
 * - MM-DD-YY → D/M/YYYY (09-01-25 → 9/1/2025)
 * - M-D-YY → D/M/YYYY (9-1-25 → 9/1/2025)
 * - Si ya está en formato correcto (D/M/YYYY o DD/MM/YYYY), lo deja igual
 *
 * Este step debe ejecutarse DESPUÉS de cargar los datos y ANTES de FilterDataByPeriodStep.
 */
final class NormalizeDateFormatsStep implements ProcessingStepInterface
{
    /**
     * Mapeo de tablas y columnas de fecha que requieren normalización.
     */
    private const DATE_FIELDS = [
        'data_source_bascar' => ['fecha_inicio_vig', 'fecha_finalizacion', 'fecha_expedicion'],
        'data_source_dettra' => ['fecha_ini_cobert', 'fech_nacim'],
    ];

    public function getName(): string
    {
        return 'Normalizar formatos de fechas desde Excel';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Normalizando formatos de fechas', ['run_id' => $run->id]);

        foreach (self::DATE_FIELDS as $tableName => $columns) {
            $this->normalizeTableDates($tableName, $columns, $run);
        }

        Log::info('Normalización de formatos completada', ['run_id' => $run->id]);
    }

    /**
     * Normaliza fechas de una tabla específica.
     */
    private function normalizeTableDates(string $tableName, array $columns, CollectionNoticeRun $run): void
    {
        $recordCount = DB::table($tableName)->where('run_id', $run->id)->count();

        if ($recordCount === 0) {
            return;
        }

        // Construir SET clause para todas las columnas
        $setClauses = array_map(function ($column) {
            return "{$column} = " . $this->buildDateNormalizationSQL($column);
        }, $columns);

        $setClause = implode(', ', $setClauses);

        // Ejecutar UPDATE masivo
        $updated = DB::update("
            UPDATE {$tableName}
            SET {$setClause}
            WHERE run_id = ?
        ", [$run->id]);

        // Contar registros por formato después de la conversión
        $stats = $this->getDateFormatStats($tableName, $columns[0], $run);

        Log::info("Fechas normalizadas en {$tableName}", [
            'run_id' => $run->id,
            'registros_totales' => $recordCount,
            'columnas' => $columns,
            'formato_stats' => $stats,
        ]);
    }

    /**
     * Construye la expresión SQL para normalizar una columna de fecha.
     *
     * Conversiones:
     * - MM-DD-YY → D/M/YYYY (09-01-25 → 9/1/2025)
     * - M-D-YY → D/M/YYYY (9-1-25 → 9/1/2025)
     * - D/M/YYYY → sin cambio (ya correcto)
     * - DD/MM/YYYY → sin cambio (ya correcto)
     */
    private function buildDateNormalizationSQL(string $column): string
    {
        return "
        CASE
            -- Si es NULL o vacío, mantener NULL
            WHEN {$column} IS NULL OR TRIM({$column}) = '' THEN NULL

            -- Si ya está en formato correcto D/M/YYYY o DD/MM/YYYY (con slash y año de 4 dígitos)
            WHEN {$column} ~ '^[0-9]{1,2}/[0-9]{1,2}/[0-9]{4}$' THEN {$column}

            -- Si es formato MM-DD-YY o M-D-YY (guión como separador y año de 2 dígitos)
            -- Convertir: MM-DD-YY → D/M/YYYY (09-01-25 → 9/1/2025)
            WHEN {$column} ~ '^[0-9]{1,2}-[0-9]{1,2}-[0-9]{2}$' THEN
                CONCAT(
                    CAST(SPLIT_PART({$column}, '-', 2) AS INTEGER),  -- día sin padding (09 → 9)
                    '/',
                    CAST(SPLIT_PART({$column}, '-', 1) AS INTEGER),  -- mes sin padding (01 → 1)
                    '/',
                    '20' || SPLIT_PART({$column}, '-', 3)            -- año completo (25 → 2025)
                )

            -- Si no coincide con ningún formato conocido, dejar como está
            ELSE {$column}
        END
        ";
    }

    /**
     * Obtiene estadísticas de formatos de fecha en una tabla.
     */
    private function getDateFormatStats(string $tableName, string $column, CollectionNoticeRun $run): array
    {
        $stats = DB::select("
            SELECT
                COUNT(*) FILTER (WHERE {$column} ~ '^[0-9]{1,2}/[0-9]{1,2}/[0-9]{4}$') as formato_correcto,
                COUNT(*) FILTER (WHERE {$column} ~ '^[0-9]{1,2}-[0-9]{1,2}-[0-9]{2}$') as formato_excel,
                COUNT(*) FILTER (WHERE {$column} IS NULL OR TRIM({$column}) = '') as nulos,
                COUNT(*) FILTER (
                    WHERE {$column} IS NOT NULL
                    AND TRIM({$column}) != ''
                    AND {$column} !~ '^[0-9]{1,2}/[0-9]{1,2}/[0-9]{4}$'
                    AND {$column} !~ '^[0-9]{1,2}-[0-9]{1,2}-[0-9]{2}$'
                ) as otros_formatos
            FROM {$tableName}
            WHERE run_id = ?
        ", [$run->id]);

        return [
            'correcto_d_m_yyyy' => $stats[0]->formato_correcto ?? 0,
            'excel_mm_dd_yy' => $stats[0]->formato_excel ?? 0,
            'nulos_vacios' => $stats[0]->nulos ?? 0,
            'otros_formatos' => $stats[0]->otros_formatos ?? 0,
        ];
    }
}
