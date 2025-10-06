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
     * Solo 1 intento para evitar duplicación de datos.
     */
    public int $tries = 1;

    private const TABLE_MAP = [
        'BASCAR' => 'data_source_bascar',
        'PAGAPL' => 'data_source_pagapl',
        'BAPRPO' => 'data_source_baprpo',
        'PAGPLA' => 'data_source_pagpla',
        'DATPOL' => 'data_source_datpol',
        'DETTRA' => 'data_source_dettra',
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

        $runId = $file->collection_notice_run_id;
        $tableName = self::TABLE_MAP[$this->dataSourceCode] ?? null;

        if ($tableName === null) {
            throw new \RuntimeException("Data source no soportado: {$this->dataSourceCode}");
        }

        Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        Log::info('🚀 INICIANDO IMPORTACIÓN EXCEL');
        Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        Log::info('📊 Data Source: ' . $this->dataSourceCode);
        Log::info('📁 Archivo: ' . basename($file->path));
        Log::info('💾 Tamaño: ' . round($file->size / 1024 / 1024, 2) . ' MB');
        Log::info('🎯 Tabla destino: ' . $tableName);
        Log::info('⚙️  Método: Go Excelize → PostgreSQL COPY');
        Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // IDEMPOTENCIA: Limpiar tabla antes de insertar para evitar duplicados
        Log::info('Limpiando tabla Excel para garantizar idempotencia', [
            'table' => $tableName,
            'run_id' => $runId,
        ]);

        $deleted = DB::table($tableName)->where('run_id', $runId)->delete();
        if ($deleted > 0) {
            Log::warning('Registros previos eliminados (idempotencia)', [
                'table' => $tableName,
                'run_id' => $runId,
                'deleted_rows' => $deleted,
            ]);
        }

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

            Log::info('');
            Log::info('✅ CONVERSIÓN EXCEL → CSV COMPLETADA');
            Log::info('📋 Total de hojas: ' . count($conversionResult['sheets']));
            Log::info('📄 Hojas procesadas: ' . implode(', ', array_keys($conversionResult['sheets'])));
            Log::info('');

            // Paso 2: Obtener columnas de la tabla destino (excluir id y created_at)
            $columns = $this->getTableColumns($tableName);

            // Paso 3: Importar cada CSV con COPY FROM STDIN (10-50x más rápido)
            Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            Log::info('⬆️  INICIANDO IMPORTACIÓN A BASE DE DATOS');
            Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

            $totalRowsImported = 0;

            foreach ($conversionResult['sheets'] as $sheetName => $sheetInfo) {
                $csvPath = $disk->path($sheetInfo['path']);
                $csvPaths[] = $csvPath;

                // Asegurar que $sheetName sea string (puede venir como int si es numérico)
                $sheetName = (string) $sheetName;

                Log::info('');
                Log::info('📄 Procesando hoja: ' . $sheetName);
                Log::info('   ├─ Filas esperadas: ' . number_format($sheetInfo['rows']));
                Log::info('   └─ Tamaño: ' . round($sheetInfo['size'] / 1024 / 1024, 2) . ' MB');

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

                $rowsPerSecond = $result['duration_ms'] > 0
                    ? round($result['rows'] / ($result['duration_ms'] / 1000))
                    : 0;

                Log::info('   ✅ Importación completada');
                Log::info('   ├─ Registros: ' . number_format($result['rows']));
                Log::info('   ├─ Duración: ' . round($result['duration_ms'] / 1000, 2) . 's');
                Log::info('   └─ Velocidad: ' . number_format($rowsPerSecond) . ' filas/seg');
            }

            Log::info('');
            Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            Log::info('🎉 IMPORTACIÓN EXCEL COMPLETADA EXITOSAMENTE');
            Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            Log::info('📊 Total de hojas: ' . count($conversionResult['sheets']));
            Log::info('📈 Total de registros: ' . number_format($totalRowsImported));
            Log::info('✅ Data Source: ' . $this->dataSourceCode);
            Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            Log::info('');

        } catch (Throwable $exception) {
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

            Log::info('CSVs temporales eliminados', [
                'file_id' => $this->fileId,
                'temp_dir' => $tempDir,
            ]);
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

        // Log para debugging
        Log::info('Normalizando CSV', [
            'csv_path' => basename($csvPath),
            'expected_columns' => count($expectedColumns),
            'sheet_name' => $sheetName,
            'has_sheet_name_column' => in_array('sheet_name', $expectedColumns),
        ]);

        // Escribir header normalizado
        fwrite($output, implode($delimiter, $expectedColumns) . "\n");

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
                    // Escapar comillas dobles para PostgreSQL COPY
                    $value = str_replace('"', '""', $data[$sourceIndex]);
                    $normalizedRow[] = $value;
                } else {
                    $normalizedRow[] = ''; // Valor vacío para columnas faltantes
                }
            }

            fwrite($output, implode($delimiter, $normalizedRow) . "\n");
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
        Log::error('Job de carga optimizada falló definitivamente', [
            'file_id' => $this->fileId,
            'data_source' => $this->dataSourceCode,
            'error' => $exception->getMessage(),
        ]);
    }
}
