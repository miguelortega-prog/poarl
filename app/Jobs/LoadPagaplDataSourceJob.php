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
 * Job para cargar archivo PAGAPL (Pagos Aplicados) a base de datos.
 *
 * Carga solo la hoja correspondiente al periodo del run.
 * Este job se ejecuta en paralelo con otros jobs de carga.
 */
final class LoadPagaplDataSourceJob implements ShouldQueue
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
     * Tiempo máximo de ejecución (20 minutos).
     */
    public int $timeout = 1200;

    /**
     * Código del data source.
     */
    private const PAGAPL_CODE = 'PAGAPL';

    /**
     * Tamaño de chunk optimizado para Excel grandes.
     */
    private const CHUNK_SIZE = 10000;

    /**
     * @param int $runId ID del run a procesar
     * @param string $period Periodo (ej: "202508")
     */
    public function __construct(
        private readonly int $runId,
        private readonly string $period
    ) {
        // Aumentar límite de memoria ANTES de cualquier operación
        ini_set('memory_limit', '2048M');

        $this->onQueue('excel-loading');
    }

    /**
     * Ejecuta el job de carga de PAGAPL.
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

        Log::info('Iniciando carga de PAGAPL a BD', [
            'job' => self::class,
            'run_id' => $this->runId,
            'period' => $this->period,
            'memory_limit' => ini_get('memory_limit'),
        ]);

        try {
            $run = CollectionNoticeRun::with(['files.dataSource'])->findOrFail($this->runId);

            // Buscar archivo PAGAPL
            $pagaplFile = null;
            foreach ($run->files as $file) {
                if (($file->dataSource->code ?? '') === self::PAGAPL_CODE) {
                    $pagaplFile = $file;
                    break;
                }
            }

            if ($pagaplFile === null) {
                throw new RuntimeException('Archivo PAGAPL no encontrado en el run');
            }

            $disk = $filesystem->disk('collection');
            $absolutePath = $disk->path($pagaplFile->path);

            if (!file_exists($absolutePath)) {
                throw new RuntimeException(
                    sprintf('Archivo PAGAPL no encontrado: %s', $absolutePath)
                );
            }

            Log::info('Archivo PAGAPL encontrado', [
                'run_id' => $run->id,
                'file_path' => $pagaplFile->path,
                'file_size_mb' => round(filesize($absolutePath) / 1024 / 1024, 2),
            ]);

            // Obtener nombres de hojas disponibles
            $reader = new Reader();
            $reader->open($absolutePath);

            $sheetNames = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheetNames[] = $sheet->getName();
            }
            $reader->close();

            Log::info('Hojas disponibles en PAGAPL', [
                'run_id' => $run->id,
                'sheets' => $sheetNames,
            ]);

            // Determinar hoja a cargar según periodo
            $targetSheetName = $this->determineTargetSheet($sheetNames, $this->period);

            Log::info('Hoja de PAGAPL seleccionada', [
                'run_id' => $run->id,
                'sheet_name' => $targetSheetName,
                'period' => $this->period,
            ]);

            // Cargar hoja a BD con streaming
            $totalRows = $this->loadSheetToDb(
                $absolutePath,
                $targetSheetName,
                $run->id,
                $tableManager
            );

            Log::info('Carga de PAGAPL completada exitosamente', [
                'job' => self::class,
                'run_id' => $run->id,
                'sheet_name' => $targetSheetName,
                'total_rows' => $totalRows,
            ]);
        } catch (Throwable $exception) {
            Log::error('Error en carga de PAGAPL', [
                'job' => self::class,
                'run_id' => $this->runId,
                'period' => $this->period,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }
    }

    /**
     * Determina el nombre de la hoja a cargar según el periodo.
     */
    private function determineTargetSheet(array $sheetNames, string $period): string
    {
        // Si el periodo es "Todos los periodos" (*), lanzar excepción
        // (esto debería manejarse con un job diferente que cargue todas las hojas)
        if ($period === '*') {
            throw new RuntimeException(
                'Periodo "*" (todos los periodos) no soportado en este job. Use LoadAllPagaplSheetsJob.'
            );
        }

        // Extraer año del periodo (primeros 4 caracteres)
        $year = substr($period, 0, 4);

        // Buscar hoja que contenga el año
        foreach ($sheetNames as $sheetName) {
            if (str_contains($sheetName, $year)) {
                return $sheetName;
            }
        }

        throw new RuntimeException(
            sprintf(
                'No se encontró una hoja en PAGAPL que contenga el año "%s". Hojas disponibles: %s',
                $year,
                implode(', ', $sheetNames)
            )
        );
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

            Log::info('Iniciando carga streaming de hoja PAGAPL', [
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
                        self::PAGAPL_CODE,
                        $runId,
                        $chunkData
                    );
                    $totalInserted += $inserted;

                    // Log cada 5 chunks (menos verbose)
                    if ((int) ($totalInserted / self::CHUNK_SIZE) % 5 === 0) {
                        Log::info('Progreso carga PAGAPL', [
                            'run_id' => $runId,
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
                    self::PAGAPL_CODE,
                    $runId,
                    $chunkData
                );
                $totalInserted += $inserted;
            }

            break; // Solo procesar una hoja
        }

        $reader->close();

        if (!$targetSheetFound) {
            throw new RuntimeException(
                sprintf('No se encontró la hoja "%s" en el archivo Excel', $sheetName)
            );
        }

        Log::info('Hoja PAGAPL cargada a BD exitosamente', [
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
        Log::error('Job de carga PAGAPL falló definitivamente', [
            'job' => self::class,
            'run_id' => $this->runId,
            'period' => $this->period,
            'error' => $exception->getMessage(),
        ]);
    }
}
