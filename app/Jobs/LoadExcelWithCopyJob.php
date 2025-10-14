<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CollectionNoticeRunFile;
use App\Services\Recaudo\GoExcelConverter;
use App\Services\Recaudo\PostgreSQLCopyImporter;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Job ULTRA-OPTIMIZADO para cargar Excel masivo a PostgreSQL usando Go + COPY.
 *
 * Flujo:
 * 1. Convierte Excel → CSVs con Go binario (8-10x más rápido que PHP, ~40 MB/s)
 * 2. Usa PostgreSQL COPY FROM STDIN para importar (10-50x más rápido que chunks)
 * 3. Limpia CSVs temporales
 *
 * Performance esperada:
 * - Excel 200MB: ~5s conversión + ~2s COPY = ~7s total (vs ~50s con PHP chunks)
 */
class LoadExcelWithCopyJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Tiempo máximo de ejecución (60 minutos para archivos Excel grandes).
     */
    public int $timeout = 3600;

    /**
     * Número de intentos del job.
     * SIN REINTENTOS: archivos Excel grandes son complejos y largos,
     * los reintentos generan colisiones de archivos temporales y datos.
     * La idempotencia está garantizada limpiando la tabla antes de insertar.
     */
    public int $tries = 1;

    private const TABLE_MAP = [
        'BASCAR' => 'data_source_bascar',
        'PAGAPL' => 'data_source_pagapl',
        'BAPRPO' => 'data_source_baprpo',
        'PAGPLA' => 'data_source_pagpla',
        'DATPOL' => 'data_source_datpol',
        'DETTRA' => 'data_source_dettra',
        'BASACT' => 'data_source_basact',
        'PAGLOG' => 'data_source_paglog',
    ];

    public function __construct(
        private readonly int $fileId,
        private readonly string $dataSourceCode
    ) {
        $this->onQueue('default');
    }

    public function handle(
        GoExcelConverter $converter,
        PostgreSQLCopyImporter $importer,
        FilesystemFactory $filesystem
    ): void {
        $file = CollectionNoticeRunFile::with(['run', 'dataSource'])->find($this->fileId);

        if ($file === null) {
            Log::warning('Archivo no encontrado para carga optimizada', [
                'file_id' => $this->fileId,
            ]);
            return;
        }

        // Verificar si el archivo ya fue importado completamente
        if ($file->isCompleted()) {
            Log::info('Archivo ya fue importado, omitiendo', [
                'file_id' => $this->fileId,
                'data_source' => $this->dataSourceCode,
                'completed_at' => $file->import_completed_at,
            ]);
            return;
        }

        $runId = $file->collection_notice_run_id;
        $tableName = self::TABLE_MAP[$this->dataSourceCode] ?? null;

        if ($tableName === null) {
            throw new \RuntimeException("Data source no soportado: {$this->dataSourceCode}");
        }

        // Marcar archivo como en proceso
        $file->markAsProcessing();

        Log::info('Iniciando importación Excel', [
            'data_source' => $this->dataSourceCode,
            'table' => $tableName,
            'run_id' => $runId,
            'file_id' => $this->fileId,
        ]);

        $deleted = DB::table($tableName)->where('run_id', $runId)->delete();

        $disk = $filesystem->disk($file->disk);
        $tempDir = 'temp/excel_import_' . $this->fileId;
        $csvPaths = [];

        try {
            // Paso 1: Convertir Excel a CSVs usando Go (8-10x más rápido que PHP)
            $conversionResult = $converter->convertAllSheetsToSeparateCSVs(
                $disk,
                $file->path,
                $tempDir
            );

            // Paso 2: Obtener columnas de la tabla destino (excluir id y created_at)
            $columns = $this->getTableColumns($tableName);

            // Paso 3: Importar cada CSV con COPY FROM STDIN (10-50x más rápido)
            $totalRowsImported = 0;

            foreach ($conversionResult['sheets'] as $sheetName => $sheetInfo) {
                $csvPath = $disk->path($sheetInfo['path']);
                $csvPaths[] = $csvPath;

                // Asegurar que $sheetName sea string (puede venir como int si es numérico)
                $sheetName = (string) $sheetName;

                // Paso 1: Normalizar CSV para que tenga todas las columnas de la tabla
                $normalizedCsv = $this->normalizeCSV($csvPath, $columns, ';', $sheetName);
                $csvPaths[] = $normalizedCsv;

                // Paso 2: Agregar run_id al CSV normalizado
                $csvWithRunId = $this->addRunIdToCSV($normalizedCsv, $runId);
                $csvPaths[] = $csvWithRunId;

                // Paso 3: Agregar run_id a las columnas
                $columnsWithRunId = array_merge(['run_id'], $columns);

                // Paso 4: Usar COPY FROM STDIN - mucho más rápido que chunks
                $result = $importer->importFromFile(
                    $tableName,
                    $csvWithRunId,
                    $columnsWithRunId,
                    ';',
                    true // hasHeader
                );

                $totalRowsImported += $result['rows'];
            }

            // Marcar archivo como completado exitosamente
            $file->markAsCompleted();

            Log::info('Importación Excel completada', [
                'data_source' => $this->dataSourceCode,
                'run_id' => $runId,
                'file_id' => $this->fileId,
                'total_rows' => $totalRowsImported,
            ]);

        } catch (Throwable $exception) {
            // Marcar archivo como fallido
            $file->markAsFailed($exception->getMessage());

            Log::error('Error en carga optimizada Excel → COPY', [
                'file_id' => $this->fileId,
                'run_id' => $runId,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;

        } finally {
            // Limpiar CSVs temporales
            foreach ($csvPaths as $csvPath) {
                if (file_exists($csvPath)) {
                    unlink($csvPath);
                }
            }

            // Limpiar directorio temporal
            if ($disk->exists($tempDir)) {
                $disk->deleteDirectory($tempDir);
            }
        }
    }

    /**
     * Obtiene las columnas de una tabla para el COPY.
     * Excluye id, run_id y created_at porque se manejan por separado.
     */
    private function getTableColumns(string $tableName): array
    {
        $columns = DB::select(
            "SELECT column_name
             FROM information_schema.columns
             WHERE table_name = ?
             AND column_name NOT IN ('id', 'run_id', 'created_at')
             ORDER BY ordinal_position",
            [$tableName]
        );

        return array_column($columns, 'column_name');
    }

    /**
     * Normaliza un CSV para que tenga todas las columnas esperadas.
     * Agrega columnas faltantes con valores vacíos.
     * Si existe columna 'sheet_name', la llena con el nombre de la hoja.
     *
     * @param string $csvPath Ruta al CSV original
     * @param array $expectedColumns Lista de columnas esperadas (sin run_id)
     * @param string $delimiter Delimitador del CSV
     * @param string|null $sheetName Nombre de la hoja (para columna sheet_name)
     * @return string Ruta al CSV normalizado
     */
    private function normalizeCSV(
        string $csvPath,
        array $expectedColumns,
        string $delimiter = ';',
        ?string $sheetName = null
    ): string {
        $outputPath = $csvPath . '.normalized.csv';
        $input = fopen($csvPath, 'r');
        $output = fopen($outputPath, 'w');

        // Leer header del CSV y splitear por delimitador
        $headerLine = fgets($input);
        $csvHeaders = explode($delimiter, trim($headerLine));

        // Normalizar headers a minúsculas para comparación case-insensitive
        $csvHeadersLower = array_map('strtolower', $csvHeaders);
        $expectedColumnsLower = array_map('strtolower', $expectedColumns);

        // Crear mapeo de índices: para cada columna esperada, encontrar su índice en el CSV
        $columnMapping = [];
        foreach ($expectedColumns as $i => $expectedCol) {
            $expectedColLower = $expectedColumnsLower[$i];
            $index = array_search($expectedColLower, $csvHeadersLower);
            $columnMapping[$expectedCol] = $index !== false ? $index : null;
        }

        // Escribir header normalizado usando fputcsv para manejar comillas correctamente
        fputcsv($output, $expectedColumns, $delimiter, '"', '\\');

        // Procesar cada línea de datos
        while (($line = fgets($input)) !== false) {
            $line = trim($line);
            if (empty($line)) {
                continue; // Saltar líneas vacías
            }

            // Usar str_getcsv para manejar correctamente comillas y delimitadores escapados
            $data = str_getcsv($line, $delimiter, '"', '\\');
            $normalizedRow = [];

            foreach ($expectedColumns as $col) {
                // Si la columna es 'sheet_name' y tenemos el nombre de la hoja, usarlo
                if (strtolower($col) === 'sheet_name' && $sheetName !== null) {
                    $normalizedRow[] = $sheetName;
                    continue;
                }

                $sourceIndex = $columnMapping[$col];
                if ($sourceIndex !== null && isset($data[$sourceIndex])) {
                    // NO escapar aquí - fputcsv lo hará automáticamente
                    $normalizedRow[] = $data[$sourceIndex];
                } else {
                    $normalizedRow[] = ''; // Valor vacío para columnas faltantes
                }
            }

            // Usar fputcsv en lugar de implode para manejar correctamente valores con delimitador
            fputcsv($output, $normalizedRow, $delimiter, '"', '\\');
        }

        fclose($input);
        fclose($output);

        return $outputPath;
    }

    /**
     * Agrega run_id al inicio de cada línea del CSV.
     */
    private function addRunIdToCSV(string $csvPath, int $runId): string
    {
        $outputPath = $csvPath . '.with_run_id.csv';
        $input = fopen($csvPath, 'r');
        $output = fopen($outputPath, 'w');

        $isFirstLine = true;
        while (($line = fgets($input)) !== false) {
            if ($isFirstLine) {
                // Agregar "run_id" al header
                fwrite($output, 'run_id;' . $line);
                $isFirstLine = false;
            } else {
                // Agregar el run_id al inicio de cada línea
                fwrite($output, $runId . ';' . $line);
            }
        }

        fclose($input);
        fclose($output);

        return $outputPath;
    }

    public function failed(Throwable $exception): void
    {
        $tableName = self::TABLE_MAP[$this->dataSourceCode] ?? null;

        Log::critical('Job de carga Excel falló definitivamente', [
            'job' => self::class,
            'file_id' => $this->fileId,
            'data_source' => $this->dataSourceCode,
            'table' => $tableName,
            'error_message' => $exception->getMessage(),
            'error_code' => $exception->getCode(),
            'error_file' => $exception->getFile(),
            'error_line' => $exception->getLine(),
        ]);

        // Limpiar datos parciales que pudieron haberse insertado antes del fallo
        if ($tableName !== null) {
            try {
                $file = CollectionNoticeRunFile::find($this->fileId);
                if ($file) {
                    $runId = $file->collection_notice_run_id;
                    DB::table($tableName)->where('run_id', $runId)->delete();
                }
            } catch (Throwable $e) {
                Log::error('Error al limpiar datos parciales en failed()', [
                    'table' => $tableName,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
