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
 * Paso para cargar la hoja correcta del archivo PAGAPL (Pagos Aplicados).
 *
 * El archivo PAGAPL es un Excel con múltiples hojas.
 * Selecciona la hoja cuyo nombre coincide con el año del periodo del run.
 *
 * Ejemplos de nombres de hojas:
 * - "2020", "2021", "2022"
 * - "2022-2023", "2024-2025"
 *
 * Para periodo 202508 o 202507, seleccionará la hoja que contenga "2025"
 */
final readonly class LoadPagaplSheetByPeriodStep implements ProcessingStepInterface
{
    private const PAGAPL_CODE = 'PAGAPL';
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
        $pagaplData = $context->getData(self::PAGAPL_CODE);

        if ($pagaplData === null) {
            throw new RuntimeException(
                'No se encontró el archivo PAGAPL en el contexto'
            );
        }

        $filePath = $pagaplData['path'] ?? null;

        if ($filePath === null) {
            throw new RuntimeException(
                'No se encontró la ruta del archivo PAGAPL'
            );
        }

        // Extraer año del periodo (primeros 4 caracteres)
        $period = $run->period;

        if ($period === null || trim($period) === '') {
            throw new RuntimeException(
                'El run no tiene un periodo definido (period es null o vacío)'
            );
        }

        Log::debug('Cargando hoja(s) de PAGAPL por periodo con OpenSpout', [
            'run_id' => $run->id,
            'period' => $period,
            'file_path' => $filePath,
        ]);

        // Obtener ruta absoluta del archivo
        $disk = $this->filesystem->disk('collection');
        $absolutePath = $disk->path($filePath);

        if (!file_exists($absolutePath)) {
            throw new RuntimeException(
                sprintf('Archivo PAGAPL no encontrado: %s', $absolutePath)
            );
        }

        // Cargar el archivo Excel con OpenSpout (streaming, bajo consumo de memoria)
        Log::info('Iniciando carga de archivo Excel PAGAPL a BD con OpenSpout', [
            'run_id' => $run->id,
            'file_size_mb' => round(filesize($absolutePath) / 1024 / 1024, 2),
        ]);

        // Abrir Excel con OpenSpout para obtener nombres de hojas
        $reader = new Reader();
        $reader->open($absolutePath);

        $sheetNames = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $sheetNames[] = $sheet->getName();
        }
        $reader->close();

        Log::debug('Hojas disponibles en PAGAPL', [
            'run_id' => $run->id,
            'sheets' => $sheetNames,
        ]);

        // Si el periodo es "Todos los periodos" (*), cargar todas las hojas
        if ($period === '*') {
            return $this->loadAllSheetsToDb($context, $pagaplData, $absolutePath, $sheetNames, $run);
        }

        // Extraer año del periodo específico
        $year = substr($period, 0, 4);

        // Buscar el nombre de la hoja que contenga el año del periodo
        $targetSheetName = null;

        foreach ($sheetNames as $sheetName) {
            // Verificar si el nombre de la hoja contiene el año
            if (str_contains($sheetName, $year)) {
                $targetSheetName = $sheetName;
                break;
            }
        }

        if ($targetSheetName === null) {
            throw new RuntimeException(
                sprintf(
                    'No se encontró una hoja en PAGAPL que contenga el año "%s". Hojas disponibles: %s',
                    $year,
                    implode(', ', $sheetNames)
                )
            );
        }

        Log::info('Hoja de PAGAPL seleccionada', [
            'run_id' => $run->id,
            'sheet_name' => $targetSheetName,
            'year' => $year,
        ]);

        // Cargar la hoja a BD con streaming
        $totalRows = $this->loadSheetToDbWithStreaming($absolutePath, $targetSheetName, $run->id);

        Log::info('Datos de PAGAPL cargados a BD exitosamente', [
            'run_id' => $run->id,
            'sheet_name' => $targetSheetName,
            'total_rows' => $totalRows,
        ]);

        // Actualizar datos de PAGAPL en el contexto
        return $context->addData(self::PAGAPL_CODE, [
            ...$pagaplData,
            'sheet_name' => $targetSheetName,
            'year' => $year,
            'loaded_to_db' => true,
            'rows_count' => $totalRows,
        ])->addStepResult($this->getName(), [
            'sheet_name' => $targetSheetName,
            'year' => $year,
            'total_rows' => $totalRows,
        ]);
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'Cargar hoja de PAGAPL por periodo';
    }

    /**
     * @param ProcessingContextDto $context
     *
     * @return bool
     */
    public function shouldExecute(ProcessingContextDto $context): bool
    {
        // Solo ejecutar si PAGAPL existe y NO está cargado a BD aún
        $pagaplData = $context->getData(self::PAGAPL_CODE);

        return $pagaplData !== null
            && ($pagaplData['is_excel'] ?? false)
            && !($pagaplData['loaded_to_db'] ?? false);
    }

    /**
     * Carga una hoja de Excel a base de datos con streaming (OpenSpout).
     * Optimizado para archivos muy grandes (>100 MB) con bajo consumo de memoria.
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
                        self::PAGAPL_CODE,
                        $runId,
                        $chunkData
                    );
                    $totalInserted += $inserted;

                    Log::info('Chunk de PAGAPL procesado', [
                        'run_id' => $runId,
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

        Log::info('Hoja cargada a BD exitosamente con OpenSpout', [
            'run_id' => $runId,
            'sheet_name' => $sheetName,
            'total_rows_inserted' => $totalInserted,
        ]);

        return $totalInserted;
    }

    /**
     * Carga todas las hojas del archivo Excel a BD.
     *
     * @param ProcessingContextDto $context
     * @param array<string, mixed> $pagaplData
     * @param string $filePath Ruta absoluta del archivo
     * @param array<int, string> $sheetNames
     * @param \App\Models\CollectionNoticeRun $run
     *
     * @return ProcessingContextDto
     */
    private function loadAllSheetsToDb(
        ProcessingContextDto $context,
        array $pagaplData,
        string $filePath,
        array $sheetNames,
        $run
    ): ProcessingContextDto {
        Log::info('Cargando todas las hojas de PAGAPL a BD (Todos los periodos)', [
            'run_id' => $run->id,
            'sheets_count' => count($sheetNames),
            'sheets' => $sheetNames,
        ]);

        $totalRows = 0;
        $processedSheets = [];

        foreach ($sheetNames as $sheetName) {
            $rows = $this->loadSheetToDbWithStreaming($filePath, $sheetName, $run->id);

            $totalRows += $rows;
            $processedSheets[] = [
                'name' => $sheetName,
                'rows_count' => $rows,
            ];

            Log::info('Hoja de PAGAPL cargada a BD', [
                'run_id' => $run->id,
                'sheet_name' => $sheetName,
                'rows_count' => $rows,
            ]);
        }

        Log::info('Todas las hojas de PAGAPL cargadas a BD', [
            'run_id' => $run->id,
            'sheets_processed' => count($processedSheets),
            'total_rows' => $totalRows,
        ]);

        // Actualizar datos de PAGAPL en el contexto
        return $context->addData(self::PAGAPL_CODE, [
            ...$pagaplData,
            'sheet_name' => 'Todas las hojas',
            'sheets_processed' => $processedSheets,
            'year' => '*',
            'loaded_to_db' => true,
            'rows_count' => $totalRows,
        ])->addStepResult($this->getName(), [
            'sheet_name' => 'Todas las hojas',
            'sheets_count' => count($processedSheets),
            'year' => '*',
            'total_rows' => $totalRows,
        ]);
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
