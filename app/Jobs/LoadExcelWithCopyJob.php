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

    public int $timeout = 1800; // 30 minutos
    public int $tries = 2;

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
        $this->onQueue('collection-notices');
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

        Log::info('🚀 Iniciando carga ULTRA-OPTIMIZADA Excel → Go → PostgreSQL COPY', [
            'file_id' => $this->fileId,
            'run_id' => $runId,
            'data_source' => $this->dataSourceCode,
            'table' => $tableName,
            'file_path' => $file->path,
            'size_mb' => round($file->size / 1024 / 1024, 2),
            'method' => 'Go excelize + PostgreSQL COPY FROM STDIN',
        ]);

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

            Log::info('✅ Excel convertido a CSVs con Go', [
                'file_id' => $this->fileId,
                'total_sheets' => count($conversionResult['sheets']),
                'sheets' => array_keys($conversionResult['sheets']),
            ]);

            // Paso 2: Obtener columnas de la tabla destino (excluir id y created_at)
            $columns = $this->getTableColumns($tableName);

            // Paso 3: Importar cada CSV con COPY FROM STDIN (10-50x más rápido)
            Log::info('Iniciando importación OPTIMIZADA con PostgreSQL COPY', [
                'file_id' => $this->fileId,
                'total_sheets' => count($conversionResult['sheets']),
                'method' => 'COPY FROM STDIN',
            ]);

            $totalRowsImported = 0;

            foreach ($conversionResult['sheets'] as $sheetName => $sheetInfo) {
                $csvPath = $disk->path($sheetInfo['path']);
                $csvPaths[] = $csvPath;

                Log::info('Importando hoja con PostgreSQL COPY', [
                    'sheet_name' => $sheetName,
                    'csv_path' => $csvPath,
                    'expected_rows' => $sheetInfo['rows'],
                    'file_size_mb' => round($sheetInfo['size'] / 1024 / 1024, 2),
                ]);

                // Usar COPY FROM STDIN - mucho más rápido que chunks
                $result = $importer->importFromFile(
                    $tableName,
                    $csvPath,
                    $columns,
                    ';',
                    true // hasHeader
                );

                $totalRowsImported += $result['rows'];

                Log::info('Hoja importada exitosamente con COPY', [
                    'sheet_name' => $sheetName,
                    'rows_imported' => $result['rows'],
                    'duration_ms' => $result['duration_ms'],
                    'rows_per_second' => $result['duration_ms'] > 0
                        ? round($result['rows'] / ($result['duration_ms'] / 1000))
                        : 0,
                ]);
            }

            Log::info('Todas las hojas importadas con COPY', [
                'file_id' => $this->fileId,
                'total_rows' => $totalRowsImported,
                'total_sheets' => count($conversionResult['sheets']),
            ]);

            // Paso 4: Actualizar metadata del archivo
            $file->update([
                'metadata' => array_merge($file->metadata ?? [], [
                    'loaded_with_copy' => true,
                    'converter' => 'Go excelize',
                    'total_sheets' => count($conversionResult['sheets']),
                    'total_rows_imported' => $totalRowsImported,
                    'sheets' => array_keys($conversionResult['sheets']),
                    'loaded_at' => now()->toIso8601String(),
                    'import_method' => 'Go + PostgreSQL COPY FROM STDIN',
                ]),
            ]);

            Log::info('🎉 Carga ULTRA-OPTIMIZADA completada (Go + COPY)', [
                'file_id' => $this->fileId,
                'run_id' => $runId,
                'total_sheets' => count($conversionResult['sheets']),
                'total_rows' => $totalRowsImported,
                'method' => 'Go excelize + COPY FROM STDIN',
            ]);

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
     */
    private function getTableColumns(string $tableName): array
    {
        $columns = DB::select(
            "SELECT column_name
             FROM information_schema.columns
             WHERE table_name = ?
             AND column_name NOT IN ('id', 'created_at')
             ORDER BY ordinal_position",
            [$tableName]
        );

        return array_column($columns, 'column_name');
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
