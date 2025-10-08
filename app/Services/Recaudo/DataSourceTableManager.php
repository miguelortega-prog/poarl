<?php

declare(strict_types=1);

namespace App\Services\Recaudo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servicio para manejar tablas temporales de data sources.
 *
 * Gestiona la carga de archivos CSV/Excel en tablas de base de datos
 * por run_id para permitir procesamiento eficiente con SQL.
 */
final class DataSourceTableManager
{
    private const CHUNK_SIZE = 5000;

    private const TABLE_MAP = [
        'BASCAR' => 'data_source_bascar',
        'PAGAPL' => 'data_source_pagapl',
        'BAPRPO' => 'data_source_baprpo',
        'PAGPLA' => 'data_source_pagpla',
        'DATPOL' => 'data_source_datpol',
        'DETTRA' => 'data_source_dettra',
    ];

    /**
     * Inserta datos en la tabla de un data source en chunks.
     *
     * @param string $dataSourceCode Código del data source (BASCAR, PAGAPL, etc.)
     * @param int $runId ID del run
     * @param array<int, array<string, mixed>> $rows Datos a insertar
     *
     * @return int Número de filas insertadas
     */
    public function insertDataInChunks(string $dataSourceCode, int $runId, array $rows): int
    {
        $tableName = $this->getTableName($dataSourceCode);
        $totalInserted = 0;
        $chunks = array_chunk($rows, self::CHUNK_SIZE);

        Log::debug('Insertando datos en chunks', [
            'table' => $tableName,
            'run_id' => $runId,
            'total_rows' => count($rows),
            'chunks' => count($chunks),
        ]);

        foreach ($chunks as $chunkIndex => $chunk) {
            $preparedChunk = $this->prepareDataForInsertion($dataSourceCode, $runId, $chunk);

            DB::table($tableName)->insert($preparedChunk);
            $totalInserted += count($preparedChunk);

            if (($chunkIndex + 1) % 5 === 0) {
                Log::debug('Progreso inserción en BD', [
                    'table' => $tableName,
                    'run_id' => $runId,
                    'chunks_processed' => $chunkIndex + 1,
                    'total_chunks' => count($chunks),
                    'rows_inserted' => $totalInserted,
                ]);
            }

            // Liberar memoria después de cada chunk
            unset($preparedChunk);
        }

        Log::info('Datos insertados exitosamente', [
            'table' => $tableName,
            'run_id' => $runId,
            'total_inserted' => $totalInserted,
        ]);

        return $totalInserted;
    }

    /**
     * Prepara los datos para inserción según el data source.
     *
     * @param string $dataSourceCode
     * @param int $runId
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private function prepareDataForInsertion(string $dataSourceCode, int $runId, array $rows): array
    {
        return match ($dataSourceCode) {
            'BASCAR' => $this->prepareBascarData($runId, $rows),
            'PAGAPL' => $this->preparePagaplData($runId, $rows),
            default => $this->prepareGenericData($runId, $rows),
        };
    }

    /**
     * Prepara datos de BASCAR para inserción.
     *
     * @param int $runId
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private function prepareBascarData(int $runId, array $rows): array
    {
        $prepared = [];

        foreach ($rows as $row) {
            // Normalizar valor_total_fact (formato colombiano)
            // Puede venir como:
            // - "1.296.926" (miles con punto, sin decimales)
            // - "70.905" (miles con punto O decimal con punto - ambiguo)
            // - "433.245" (miles con punto)
            // Estrategia: Eliminar TODOS los puntos, mantener comas como decimal
            $valorTotalFact = $row['VALOR_TOTAL_FACT'] ?? null;
            if ($valorTotalFact !== null && is_string($valorTotalFact)) {
                $valorTotalFact = trim($valorTotalFact);
                if ($valorTotalFact !== '') {
                    // Eliminar todos los puntos (tanto separadores de miles como decimales)
                    $valorTotalFact = str_replace('.', '', $valorTotalFact);
                    // Convertir coma a punto si es separador decimal
                    $valorTotalFact = str_replace(',', '.', $valorTotalFact);
                } else {
                    $valorTotalFact = null;
                }
            }

            $prepared[] = [
                'run_id' => $runId,
                'num_tomador' => $row['NUM_TOMADOR'] ?? null,
                'fecha_inicio_vig' => $row['FECHA_INICIO_VIG'] ?? null,
                'valor_total_fact' => $valorTotalFact,
                'periodo' => null, // Se calcula después
                'composite_key' => null, // Se calcula después
                'data' => json_encode($row),
                'created_at' => now(),
            ];
        }

        return $prepared;
    }

    /**
     * Prepara datos de PAGAPL para inserción.
     *
     * @param int $runId
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private function preparePagaplData(int $runId, array $rows): array
    {
        $prepared = [];

        foreach ($rows as $row) {
            $prepared[] = [
                'run_id' => $runId,
                'identificacion' => $row['Identificacion'] ?? $row['identificacion'] ?? null,
                'periodo' => $row['Periodo'] ?? $row['periodo'] ?? null,
                'valor' => $row['Valor'] ?? $row['valor'] ?? null,
                'composite_key' => null, // Se calcula después
                'data' => json_encode($row),
                'created_at' => now(),
            ];
        }

        return $prepared;
    }

    /**
     * Prepara datos genéricos (otros data sources) para inserción.
     *
     * @param int $runId
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private function prepareGenericData(int $runId, array $rows): array
    {
        $prepared = [];

        foreach ($rows as $row) {
            $prepared[] = [
                'run_id' => $runId,
                'data' => json_encode($row),
                'created_at' => now(),
            ];
        }

        return $prepared;
    }

    /**
     * Limpia todos los datos de un run.
     *
     * @param int $runId
     *
     * @return int Total de filas eliminadas
     */
    public function cleanupRunData(int $runId): int
    {
        $totalDeleted = 0;

        foreach (self::TABLE_MAP as $table) {
            $deleted = DB::table($table)->where('run_id', $runId)->delete();
            $totalDeleted += $deleted;

            if ($deleted > 0) {
                Log::debug('Datos eliminados de tabla', [
                    'table' => $table,
                    'run_id' => $runId,
                    'rows_deleted' => $deleted,
                ]);
            }
        }

        Log::info('Cleanup de datos completado', [
            'run_id' => $runId,
            'total_deleted' => $totalDeleted,
        ]);

        return $totalDeleted;
    }

    /**
     * Obtiene el nombre de la tabla para un data source.
     *
     * @param string $dataSourceCode
     *
     * @return string
     */
    public function getTableName(string $dataSourceCode): string
    {
        return self::TABLE_MAP[$dataSourceCode] ?? throw new \InvalidArgumentException(
            sprintf('Data source "%s" no tiene tabla asociada', $dataSourceCode)
        );
    }

    /**
     * Cuenta los registros de un data source para un run.
     *
     * @param string $dataSourceCode
     * @param int $runId
     *
     * @return int
     */
    public function countRows(string $dataSourceCode, int $runId): int
    {
        $tableName = $this->getTableName($dataSourceCode);

        return DB::table($tableName)->where('run_id', $runId)->count();
    }
}
