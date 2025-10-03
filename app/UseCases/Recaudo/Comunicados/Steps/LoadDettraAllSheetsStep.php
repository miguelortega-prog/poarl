<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\DTOs\Recaudo\Comunicados\ProcessingContextDto;
use App\Services\Recaudo\DataSourceTableManager;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\Log;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

/**
 * Paso para cargar todas las hojas del archivo DETTRA (Detalle trabajadores).
 *
 * El archivo DETTRA es un Excel con múltiples hojas que contienen información
 * de trabajadores. Este paso carga TODAS las hojas a la tabla staging.
 *
 * Características:
 * - Procesa archivos muy grandes (>200 MB)
 * - Usa OpenSpout para streaming con bajo consumo de memoria
 * - Chunks de 5000 registros para optimizar inserciones
 * - Procesa todas las hojas del Excel
 */
final readonly class LoadDettraAllSheetsStep implements ProcessingStepInterface
{
    private const DETTRA_CODE = 'DETTRA';
    private const CHUNK_SIZE = 5000;

    public function __construct(
        private FilesystemFactory $filesystem,
        private DataSourceTableManager $tableManager
    ) {
    }

    /**
     * @param ProcessingContextDto $context
     *
     * @return ProcessingContextDto
     */
    public function execute(ProcessingContextDto $context): ProcessingContextDto
    {
        $run = $context->run;
        $dettraData = $context->getData(self::DETTRA_CODE);

        if ($dettraData === null) {
            throw new RuntimeException(
                'No se encontró el archivo DETTRA en el contexto'
            );
        }

        $filePath = $dettraData['path'] ?? null;

        if ($filePath === null) {
            throw new RuntimeException(
                'No se encontró la ruta del archivo DETTRA'
            );
        }

        Log::debug('Cargando todas las hojas de DETTRA con OpenSpout', [
            'run_id' => $run->id,
            'file_path' => $filePath,
        ]);

        // Obtener ruta absoluta del archivo
        $disk = $this->filesystem->disk('collection');
        $absolutePath = $disk->path($filePath);

        if (!file_exists($absolutePath)) {
            throw new RuntimeException(
                sprintf('Archivo DETTRA no encontrado: %s', $absolutePath)
            );
        }

        // Obtener información del archivo
        $fileSizeMb = round(filesize($absolutePath) / 1024 / 1024, 2);

        Log::info('Iniciando carga de archivo Excel DETTRA a BD con OpenSpout', [
            'run_id' => $run->id,
            'file_size_mb' => $fileSizeMb,
        ]);

        // Abrir Excel con OpenSpout para obtener nombres de hojas
        $reader = new Reader();
        $reader->open($absolutePath);

        $sheetNames = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $sheetNames[] = $sheet->getName();
        }
        $reader->close();

        Log::debug('Hojas disponibles en DETTRA', [
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

            $rows = $this->loadSheetToDbWithStreaming($absolutePath, $sheetName, $run->id);

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

        Log::info('Todas las hojas de DETTRA cargadas a BD exitosamente', [
            'run_id' => $run->id,
            'sheets_processed' => count($processedSheets),
            'total_rows' => $totalRows,
            'file_size_mb' => $fileSizeMb,
        ]);

        // Actualizar datos de DETTRA en el contexto
        return $context->addData(self::DETTRA_CODE, [
            ...$dettraData,
            'sheets_processed' => $processedSheets,
            'loaded_to_db' => true,
            'rows_count' => $totalRows,
        ])->addStepResult($this->getName(), [
            'sheets_count' => count($processedSheets),
            'total_rows' => $totalRows,
            'file_size_mb' => $fileSizeMb,
        ]);
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'Cargar todas las hojas de DETTRA';
    }

    /**
     * @param ProcessingContextDto $context
     *
     * @return bool
     */
    public function shouldExecute(ProcessingContextDto $context): bool
    {
        // Solo ejecutar si DETTRA existe y NO está cargado a BD aún
        $dettraData = $context->getData(self::DETTRA_CODE);

        return $dettraData !== null
            && ($dettraData['is_excel'] ?? false)
            && !($dettraData['loaded_to_db'] ?? false);
    }

    /**
     * Carga una hoja de Excel a base de datos con streaming (OpenSpout).
     * Optimizado para archivos muy grandes (>200 MB) con bajo consumo de memoria.
     *
     * @param string $filePath Ruta absoluta del archivo Excel
     * @param string $sheetName Nombre de la hoja a cargar
     * @param int $runId ID del run
     *
     * @return int Total de filas insertadas
     */
    private function loadSheetToDbWithStreaming(string $filePath, string $sheetName, int $runId): int
    {
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
                    $headers = array_map(fn($value) => is_string($value) ? trim($value) : $value, $rowData);
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
                    $inserted = $this->tableManager->insertDataInChunks(
                        self::DETTRA_CODE,
                        $runId,
                        $chunkData
                    );
                    $totalInserted += $inserted;

                    Log::info('Chunk de DETTRA procesado', [
                        'run_id' => $runId,
                        'sheet_name' => $sheetName,
                        'rows_processed' => $rowNumber - 1,
                        'rows_inserted' => $totalInserted,
                    ]);

                    // Limpiar chunk
                    $chunkData = [];
                    gc_collect_cycles();
                }
            }

            // Insertar chunk residual
            if (!empty($chunkData)) {
                $inserted = $this->tableManager->insertDataInChunks(
                    self::DETTRA_CODE,
                    $runId,
                    $chunkData
                );
                $totalInserted += $inserted;
            }

            break; // Solo procesar una hoja por llamada
        }

        $reader->close();

        if (!$targetSheetFound) {
            throw new RuntimeException(
                sprintf('No se encontró la hoja "%s" en el archivo Excel', $sheetName)
            );
        }

        Log::info('Hoja cargada a BD exitosamente con OpenSpout', [
            'run_id' => $runId,
            'sheet_name' => $sheetName,
            'total_rows_inserted' => $totalInserted,
        ]);

        return $totalInserted;
    }

    /**
     * Verifica si una fila está completamente vacía.
     *
     * @param array<int|string, mixed> $row
     *
     * @return bool
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
}
