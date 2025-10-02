<?php

declare(strict_types=1);

namespace App\Services\Recaudo;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

/**
 * Servicio para convertir archivos Excel (.xlsx) a CSV usando streaming con OpenSpout.
 *
 * Convierte Excel a CSV de forma eficiente en memoria, procesando
 * fila por fila con muy bajo consumo de memoria (ideal para archivos >100MB).
 */
final class ExcelToCsvConverter
{
    /**
     * Convierte un archivo Excel a CSV con streaming (OpenSpout).
     *
     * @param Filesystem $disk Disco donde está el archivo
     * @param string $excelPath Ruta relativa del archivo Excel
     * @param string $csvPath Ruta relativa donde guardar el CSV
     * @param string|null $sheetName Nombre de la hoja a convertir (null = primera hoja)
     * @param string $delimiter Delimitador del CSV (por defecto punto y coma)
     *
     * @return array{rows: int, size: int} Información del archivo generado
     *
     * @throws RuntimeException
     */
    public function convert(
        Filesystem $disk,
        string $excelPath,
        string $csvPath,
        ?string $sheetName = null,
        string $delimiter = ';'
    ): array {
        $startTime = microtime(true);

        if (!$disk->exists($excelPath)) {
            throw new RuntimeException(sprintf('Archivo Excel no encontrado: %s', $excelPath));
        }

        Log::info('Iniciando conversión Excel a CSV con OpenSpout', [
            'excel_path' => $excelPath,
            'csv_path' => $csvPath,
            'sheet_name' => $sheetName,
            'delimiter' => $delimiter,
        ]);

        $absoluteExcelPath = $disk->path($excelPath);
        $absoluteCsvPath = $disk->path($csvPath);

        // Abrir archivo CSV para escritura
        $csvHandle = fopen($absoluteCsvPath, 'w');
        if ($csvHandle === false) {
            throw new RuntimeException('No se pudo crear el archivo CSV');
        }

        $totalRows = 0;
        $targetSheetFound = false;

        try {
            $reader = new Reader();
            $reader->open($absoluteExcelPath);

            foreach ($reader->getSheetIterator() as $sheet) {
                // Si se especificó un nombre de hoja, solo procesar esa hoja
                if ($sheetName !== null && $sheet->getName() !== $sheetName) {
                    continue;
                }

                $targetSheetFound = true;

                Log::info('Procesando hoja de Excel', [
                    'sheet_name' => $sheet->getName(),
                    'excel_path' => $excelPath,
                ]);

                foreach ($sheet->getRowIterator() as $row) {
                    $cells = $row->getCells();
                    $rowData = array_map(fn($cell) => $cell->getValue(), $cells);

                    // Escribir fila al CSV
                    fputcsv($csvHandle, $rowData, $delimiter);
                    $totalRows++;

                    // Log de progreso cada 10,000 filas
                    if ($totalRows % 10000 === 0) {
                        Log::info('Progreso conversión Excel a CSV', [
                            'excel_path' => $excelPath,
                            'rows_processed' => $totalRows,
                        ]);
                    }
                }

                // Si se especificó una hoja, solo procesar esa
                if ($sheetName !== null) {
                    break;
                }

                // Si no se especificó hoja, solo procesar la primera
                if ($sheetName === null) {
                    break;
                }
            }

            $reader->close();

            if ($sheetName !== null && !$targetSheetFound) {
                throw new RuntimeException(
                    sprintf('Hoja "%s" no encontrada en el Excel', $sheetName)
                );
            }

        } finally {
            fclose($csvHandle);
        }

        $fileSize = $disk->size($csvPath);
        $duration = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('Conversión Excel a CSV completada con OpenSpout', [
            'excel_path' => $excelPath,
            'csv_path' => $csvPath,
            'rows' => $totalRows,
            'size_bytes' => $fileSize,
            'size_mb' => round($fileSize / 1024 / 1024, 2),
            'duration_ms' => $duration,
            'rows_per_second' => $duration > 0 ? round($totalRows / ($duration / 1000)) : 0,
        ]);

        return [
            'rows' => $totalRows,
            'size' => $fileSize,
        ];
    }

    /**
     * Convierte un archivo Excel a CSV y elimina el Excel original.
     *
     * @param Filesystem $disk
     * @param string $excelPath
     * @param string|null $sheetName
     * @param string $delimiter
     *
     * @return array{csv_path: string, rows: int, size: int}
     */
    public function convertAndReplace(
        Filesystem $disk,
        string $excelPath,
        ?string $sheetName = null,
        string $delimiter = ';'
    ): array {
        // Generar nombre del CSV (mismo nombre, extensión .csv)
        $csvPath = preg_replace('/\.(xlsx|xls)$/i', '.csv', $excelPath);

        if ($csvPath === $excelPath) {
            $csvPath = $excelPath . '.csv';
        }

        $result = $this->convert($disk, $excelPath, $csvPath, $sheetName, $delimiter);

        // Eliminar Excel original
        $disk->delete($excelPath);

        Log::info('Archivo Excel eliminado después de conversión', [
            'excel_path' => $excelPath,
            'csv_path' => $csvPath,
        ]);

        return [
            'csv_path' => $csvPath,
            ...$result,
        ];
    }
}
