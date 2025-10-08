<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Step: Validar integridad de datos cargados por jobs previos.
 *
 * IMPORTANTE: Este step NO carga datos, solo VALIDA que los jobs previos
 * (LoadCsvDataSourcesJob y LoadExcelWithCopyJob) hayan cargado correctamente
 * todos los data sources a sus respectivas tablas en la base de datos.
 *
 * Validaciones:
 * 1. Verifica que todas las tablas de data sources tengan registros para este run_id
 * 2. Valida que las columnas cargadas coincidan con las parametrizadas en notice_data_source_columns
 * 3. Reporta columnas faltantes o sobrantes
 * 4. Reporta estadísticas de carga
 */
final class ValidateDataIntegrityStep implements ProcessingStepInterface
{
    /**
     * Mapeo de códigos de data sources a tablas PostgreSQL.
     */
    private const TABLE_MAP = [
        'BASCAR' => 'data_source_bascar',
        'BAPRPO' => 'data_source_baprpo',
        'DATPOL' => 'data_source_datpol',
        'DETTRA' => 'data_source_dettra',
        'PAGAPL' => 'data_source_pagapl',
        'PAGPLA' => 'data_source_pagpla',
    ];

    public function getName(): string
    {
        return 'Validar integridad de datos cargados por jobs';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        $startTime = microtime(true);

        Log::info('🔍 Validando integridad de datos cargados por jobs previos', [
            'step' => self::class,
            'run_id' => $run->id,
        ]);

        // Obtener data sources esperados del tipo de comunicado
        $expectedDataSources = $run->type->dataSources;

        Log::info('Data sources esperados según tipo de comunicado', [
            'run_id' => $run->id,
            'expected' => $expectedDataSources->pluck('code')->toArray(),
        ]);

        $validationResults = [];
        $errors = [];
        $totalRecords = 0;

        // Validar cada data source esperado
        foreach ($expectedDataSources as $dataSource) {
            $code = $dataSource->code;

            if (!isset(self::TABLE_MAP[$code])) {
                $errors[] = "Data source {$code} no tiene tabla mapeada";
                Log::error('❌ Data source sin tabla mapeada', [
                    'run_id' => $run->id,
                    'data_source' => $code,
                ]);
                continue;
            }

            $tableName = self::TABLE_MAP[$code];

            // Validación 1: Contar registros para este run_id
            $recordCount = DB::table($tableName)
                ->where('run_id', $run->id)
                ->count();

            if ($recordCount === 0) {
                $errors[] = "Data source {$code} no tiene registros en BD (tabla: {$tableName})";
                Log::error('❌ Data source sin registros', [
                    'run_id' => $run->id,
                    'data_source' => $code,
                    'table' => $tableName,
                ]);
                continue;
            }

            // Validación 2: Validar columnas
            $columnValidation = $this->validateColumns($tableName, $dataSource);

            $validationResults[$code] = [
                'table' => $tableName,
                'records' => $recordCount,
                'column_validation' => $columnValidation,
            ];

            if (!empty($columnValidation['missing_columns'])) {
                $errors[] = sprintf(
                    "Data source %s: columnas faltantes en tabla %s: %s",
                    $code,
                    $tableName,
                    implode(', ', $columnValidation['missing_columns'])
                );
            }

            $totalRecords += $recordCount;

            Log::info('✅ Data source validado', [
                'run_id' => $run->id,
                'data_source' => $code,
                'table' => $tableName,
                'records' => number_format($recordCount),
                'expected_columns' => $columnValidation['expected_count'],
                'actual_columns' => $columnValidation['actual_count'],
                'missing_columns' => $columnValidation['missing_columns'],
                'extra_columns' => $columnValidation['extra_columns'],
            ]);
        }

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        // Si hay errores, lanzar excepción
        if (!empty($errors)) {
            $errorMessage = "Validación de integridad FALLÓ:\n" . implode("\n", $errors);

            Log::error('❌ Validación de integridad FALLÓ', [
                'run_id' => $run->id,
                'errors' => $errors,
                'validation_results' => $validationResults,
            ]);

            throw new RuntimeException($errorMessage);
        }

        Log::info('✅ Validación de integridad completada exitosamente', [
            'run_id' => $run->id,
            'data_sources_validated' => count($validationResults),
            'total_records_loaded' => number_format($totalRecords),
            'duration_ms' => $duration,
        ]);
    }

    /**
     * Valida que las columnas de la tabla física coincidan con las parametrizadas.
     *
     * @param string $tableName Nombre de la tabla
     * @param \App\Models\NoticeDataSource $dataSource Data source con columnas esperadas
     * @return array Resultado de validación
     */
    private function validateColumns(string $tableName, $dataSource): array
    {
        // Obtener columnas esperadas de notice_data_source_columns
        $expectedColumns = $dataSource->columns()
            ->pluck('column_name')
            ->map(function ($col) {
                // Normalización completa para coincidir con los nombres de columnas en la tabla física:
                // 1. Convertir a minúsculas
                // 2. Reemplazar caracteres especiales (espacios, puntos, etc.) por underscores
                // 3. Eliminar underscores duplicados
                $normalized = strtolower($col);
                $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);
                $normalized = trim($normalized, '_'); // Remover underscores al inicio/final
                return $normalized;
            })
            ->toArray();

        // Obtener columnas reales de la tabla (excluir id, run_id, created_at, sheet_name)
        $actualColumns = DB::select("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_name = ?
                AND column_name NOT IN ('id', 'run_id', 'created_at', 'sheet_name')
            ORDER BY ordinal_position
        ", [$tableName]);

        $actualColumns = array_map(
            fn($col) => strtolower($col->column_name),
            $actualColumns
        );

        // Comparar
        $missingColumns = array_diff($expectedColumns, $actualColumns);
        $extraColumns = array_diff($actualColumns, $expectedColumns);

        return [
            'expected_count' => count($expectedColumns),
            'actual_count' => count($actualColumns),
            'missing_columns' => array_values($missingColumns),
            'extra_columns' => array_values($extraColumns),
            'matches' => empty($missingColumns) && empty($extraColumns),
        ];
    }
}
