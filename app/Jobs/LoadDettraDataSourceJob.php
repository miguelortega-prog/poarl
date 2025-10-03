<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CollectionNoticeRun;
use App\Services\Recaudo\DataSourceTableManager;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;
use Throwable;

/**
 * Job para cargar archivo DETTRA (Detalle Trabajadores) a base de datos.
 *
 * Carga TODAS las hojas del archivo Excel.
 * Este job se ejecuta en paralelo con otros jobs de carga.
 */
final class LoadDettraDataSourceJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Número de intentos del job.
     */
    public int $tries = 2;

    /**
     * Tiempo máximo de ejecución (30 minutos).
     */
    public int $timeout = 1800;

    /**
     * Código del data source.
     */
    private const DETTRA_CODE = 'DETTRA';

    /**
     * Tamaño de chunk optimizado para Excel grandes.
     */
    private const CHUNK_SIZE = 10000;

    /**
     * @param int $runId ID del run a procesar
     */
    public function __construct(
        private readonly int $runId
    ) {
        // Aumentar límite de memoria ANTES de cualquier operación
        ini_set('memory_limit', '2048M');

        $this->onQueue('excel-loading');
    }

    /**
     * Ejecuta el job de carga de DETTRA.
     */
    public function handle(
        FilesystemFactory $filesystem,
        DataSourceTableManager $tableManager
    ): void {
        // Verificar si el batch fue cancelado
        if ($this->batch()?->cancelled()) {
            return;
        }

        // Aumentar límite de memoria para archivos Excel grandes
        ini_set('memory_limit', '2048M');

        Log::info('Iniciando carga de DETTRA a BD', [
            'job' => self::class,
            'run_id' => $this->runId,
            'memory_limit' => ini_get('memory_limit'),
        ]);

        try {
            $run = CollectionNoticeRun::with(['files.dataSource'])->findOrFail($this->runId);

            // Buscar archivo DETTRA
            $dettraFile = null;
            foreach ($run->files as $file) {
                if (($file->dataSource->code ?? '') === self::DETTRA_CODE) {
                    $dettraFile = $file;
                    break;
                }
            }

            if ($dettraFile === null) {
                throw new RuntimeException('Archivo DETTRA no encontrado en el run');
            }

            $disk = $filesystem->disk('collection');
            $absolutePath = $disk->path($dettraFile->path);

            if (!file_exists($absolutePath)) {
                throw new RuntimeException(
                    sprintf('Archivo DETTRA no encontrado: %s', $absolutePath)
                );
            }

            $fileSizeMb = round(filesize($absolutePath) / 1024 / 1024, 2);

            Log::info('Archivo DETTRA encontrado', [
                'run_id' => $run->id,
                'file_path' => $dettraFile->path,
                'file_size_mb' => $fileSizeMb,
            ]);

            // Obtener nombres de hojas disponibles
            $reader = new Reader();
            $reader->open($absolutePath);

            $sheetNames = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheetNames[] = $sheet->getName();
            }
            $reader->close();

            Log::info('Hojas disponibles en DETTRA', [
                'run_id' => $run->id,
                'sheets_count' => count($sheetNames),
                'sheets' => $sheetNames,
            ]);

            // Cargar todas las hojas
            $totalRows = 0;
            $processedSheets = [];

            foreach ($sheetNames as $sheetName) {
                Log::info('Procesando hoja de DETTRA', [
                    'run_id' => $run->id,
                    'sheet_name' => $sheetName,
                ]);

                $rows = $this->loadSheetToDb(
                    $absolutePath,
                    $sheetName,
                    $run->id,
                    $tableManager
                );

                $totalRows += $rows;
                $processedSheets[] = [
                    'name' => $sheetName,
                    'rows_count' => $rows,
                ];

                Log::info('Hoja de DETTRA cargada a BD', [
                    'run_id' => $run->id,
                    'sheet_name' => $sheetName,
                    'rows_count' => $rows,
                    'total_accumulated' => $totalRows,
                ]);
            }

            Log::info('Carga de DETTRA completada exitosamente', [
                'job' => self::class,
                'run_id' => $run->id,
                'sheets_processed' => count($processedSheets),
                'total_rows' => $totalRows,
                'file_size_mb' => $fileSizeMb,
            ]);
        } catch (Throwable $exception) {
            Log::error('Error en carga de DETTRA', [
                'job' => self::class,
                'run_id' => $this->runId,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }
    }

    /**
     * Carga una hoja de Excel a base de datos con streaming.
     */
    private function loadSheetToDb(
        string $filePath,
        string $sheetName,
        int $runId,
        DataSourceTableManager $tableManager
    ): int {
        $reader = new Reader();
        $reader->open($filePath);

        $headers = [];
        $totalInserted = 0;
        $chunkData = [];
        $rowNumber = 0;
        $targetSheetFound = false;

        foreach ($reader->getSheetIterator() as $sheet) {
            // Solo procesar la hoja objetivo
            if ($sheet->getName() !== $sheetName) {
                continue;
            }

            $targetSheetFound = true;

            Log::info('Iniciando carga streaming de hoja DETTRA', [
                'run_id' => $runId,
                'sheet_name' => $sheetName,
                'chunk_size' => self::CHUNK_SIZE,
            ]);

            foreach ($sheet->getRowIterator() as $row) {
                $rowNumber++;
                $cells = $row->getCells();
                $rowData = array_map(fn($cell) => $cell->getValue(), $cells);

                // Primera fila son los headers
                if ($rowNumber === 1) {
                    $headers = array_map(
                        fn($value) => is_string($value) ? trim($value) : $value,
                        $rowData
                    );
                    continue;
                }

                // Saltar filas vacías
                if ($this->isEmptyRow($rowData)) {
                    continue;
                }

                // Crear array asociativo
                $associativeRow = [];
                foreach ($headers as $index => $header) {
                    $associativeRow[$header] = $rowData[$index] ?? null;
                }

                $chunkData[] = $associativeRow;

                // Insertar cuando el chunk está lleno
                if (count($chunkData) >= self::CHUNK_SIZE) {
                    $inserted = $tableManager->insertDataInChunks(
                        self::DETTRA_CODE,
                        $runId,
                        $chunkData
                    );
                    $totalInserted += $inserted;

                    // Log cada 5 chunks (menos verbose)
                    if ((int) ($totalInserted / self::CHUNK_SIZE) % 5 === 0) {
                        Log::info('Progreso carga DETTRA', [
                            'run_id' => $runId,
                            'sheet_name' => $sheetName,
                            'rows_inserted' => $totalInserted,
                            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                        ]);
                    }

                    // Limpiar chunk y memoria
                    $chunkData = [];
                    gc_collect_cycles();
                }
            }

            // Insertar chunk residual
            if (!empty($chunkData)) {
                $inserted = $tableManager->insertDataInChunks(
                    self::DETTRA_CODE,
                    $runId,
                    $chunkData
                );
                $totalInserted += $inserted;
            }

            break; // Solo procesar una hoja por iteración
        }

        $reader->close();

        if (!$targetSheetFound) {
            throw new RuntimeException(
                sprintf('No se encontró la hoja "%s" en el archivo Excel', $sheetName)
            );
        }

        Log::info('Hoja DETTRA cargada a BD exitosamente', [
            'run_id' => $runId,
            'sheet_name' => $sheetName,
            'total_rows_inserted' => $totalInserted,
        ]);

        return $totalInserted;
    }

    /**
     * Verifica si una fila está completamente vacía.
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Maneja el fallo del job.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Job de carga DETTRA falló definitivamente', [
            'job' => self::class,
            'run_id' => $this->runId,
            'error' => $exception->getMessage(),
        ]);
    }
}
