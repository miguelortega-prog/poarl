<?php

declare(strict_types=1);

namespace App\Services\CollectionRun\Validators;

use App\Models\CollectionNoticeRunFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

/**
 * Validador de estructura de archivos CSV/Excel con soporte para:
 * - Lectura por chunks (grandes volúmenes)
 * - Múltiples hojas en Excel
 * - Validación de estructura sin cargar todo en memoria
 *
 * Principios SOLID aplicados:
 * - Single Responsibility: Solo valida estructura de archivos
 * - Open/Closed: Extensible para agregar nuevos formatos
 *
 * Cumple con PSR-12 y tipado fuerte.
 * Implementa prácticas OWASP: validación de entrada, prevención de DoS.
 */
final readonly class FileStructureValidator
{
    /**
     * Tamaño máximo de archivo para validación (500MB).
     */
    private const int MAX_FILE_SIZE = 500 * 1024 * 1024;

    /**
     * Tamaño de chunk para lectura de CSV (en bytes).
     */
    private const int CSV_CHUNK_SIZE = 8192;

    /**
     * Valida la estructura de un archivo.
     *
     * @param Filesystem $disk Disco donde está almacenado el archivo
     * @param CollectionNoticeRunFile $file Archivo a validar
     * @param array<int, string> $expectedColumns Columnas esperadas
     *
     * @throws RuntimeException Si el archivo no pasa la validación
     */
    public function validate(
        Filesystem $disk,
        CollectionNoticeRunFile $file,
        array $expectedColumns
    ): void {
        // Validar tamaño del archivo (OWASP: prevenir DoS)
        $fileSize = $disk->size($file->path);

        if ($fileSize === false || $fileSize > self::MAX_FILE_SIZE) {
            throw new RuntimeException(
                sprintf(
                    'El archivo "%s" excede el tamaño máximo permitido para validación.',
                    $file->original_name
                )
            );
        }

        // Determinar tipo de archivo y validar
        $extension = strtolower($file->ext ?? '');

        try {
            match ($extension) {
                'csv', 'txt' => $this->validateCsvFile($disk, $file, $expectedColumns),
                'xls', 'xlsx' => $this->validateExcelFile($disk, $file, $expectedColumns),
                default => throw new RuntimeException(
                    sprintf('Formato de archivo no soportado: %s', $extension)
                )
            };
        } catch (RuntimeException $exception) {
            // Re-lanzar excepciones de validación
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Error inesperado al validar archivo', [
                'file_id' => $file->id,
                'path' => $file->path,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException(
                sprintf(
                    'Error al procesar el archivo "%s": %s',
                    $file->original_name,
                    $exception->getMessage()
                ),
                previous: $exception
            );
        }
    }

    /**
     * Valida un archivo CSV usando lectura por chunks.
     * Detecta automáticamente el delimitador (coma, punto y coma, tab).
     *
     * @param array<int, string> $expectedColumns
     *
     * @throws RuntimeException
     */
    private function validateCsvFile(
        Filesystem $disk,
        CollectionNoticeRunFile $file,
        array $expectedColumns
    ): void {
        $stream = $disk->readStream($file->path);

        if ($stream === false) {
            throw new RuntimeException(
                sprintf('No se pudo leer el archivo "%s"', $file->original_name)
            );
        }

        try {
            // Configurar lectura con buffer
            if (is_resource($stream)) {
                stream_set_read_buffer($stream, self::CSV_CHUNK_SIZE);
            }

            // Detectar delimitador automáticamente
            $delimiter = $this->detectCsvDelimiter($stream);

            // Volver al inicio del archivo
            rewind($stream);

            // Leer solo la primera línea (encabezados) con el delimitador correcto
            $headers = fgetcsv($stream, 0, $delimiter);

            if ($headers === false || $headers === null) {
                throw new RuntimeException(
                    sprintf('El archivo "%s" está vacío o no tiene encabezados.', $file->original_name)
                );
            }

            // Normalizar encabezados (trim)
            $normalizedHeaders = array_map(
                fn ($header): string => trim((string) $header),
                $headers
            );

            // Validar columnas
            $this->validateColumns($normalizedHeaders, $expectedColumns, $file->original_name);

            Log::info('Archivo CSV validado exitosamente', [
                'file_id' => $file->id,
                'file_name' => $file->original_name,
                'columns_count' => count($normalizedHeaders),
                'delimiter' => $delimiter,
            ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Detecta automáticamente el delimitador de un archivo CSV.
     * Soporta: coma (,), punto y coma (;), tab (\t), pipe (|).
     *
     * @param resource $stream
     *
     * @return string
     */
    private function detectCsvDelimiter($stream): string
    {
        $delimiters = [';', ',', "\t", '|'];
        $firstLine = fgets($stream);

        if ($firstLine === false) {
            return ','; // Default
        }

        $maxCount = 0;
        $detectedDelimiter = ',';

        foreach ($delimiters as $delimiter) {
            $count = substr_count($firstLine, $delimiter);

            if ($count > $maxCount) {
                $maxCount = $count;
                $detectedDelimiter = $delimiter;
            }
        }

        return $detectedDelimiter;
    }

    /**
     * Valida un archivo Excel (XLS/XLSX) con múltiples hojas.
     * Para archivos grandes, solo valida la primera hoja por rendimiento.
     *
     * @param array<int, string> $expectedColumns
     *
     * @throws RuntimeException
     */
    private function validateExcelFile(
        Filesystem $disk,
        CollectionNoticeRunFile $file,
        array $expectedColumns
    ): void {
        // Para archivos muy grandes (>100MB), solo validar estructura básica
        if ($file->size > 100 * 1024 * 1024) {
            Log::warning('Archivo Excel muy grande, validación simplificada', [
                'file_id' => $file->id,
                'file_name' => $file->original_name,
                'size_mb' => round($file->size / 1024 / 1024, 2),
            ]);

            // Solo verificar que el archivo existe y es válido (validación mínima)
            $stream = $disk->readStream($file->path);
            if ($stream === false) {
                throw new RuntimeException(
                    sprintf('No se pudo leer el archivo "%s"', $file->original_name)
                );
            }
            fclose($stream);

            Log::info('Archivo Excel grande validado (verificación básica)', [
                'file_id' => $file->id,
                'file_name' => $file->original_name,
            ]);

            return;
        }

        // Para archivos < 100MB, validación completa
        $tempPath = sys_get_temp_dir() . '/' . uniqid('excel_validation_', true);

        try {
            $stream = $disk->readStream($file->path);

            if ($stream === false) {
                throw new RuntimeException(
                    sprintf('No se pudo leer el archivo "%s"', $file->original_name)
                );
            }

            file_put_contents($tempPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $reader = IOFactory::createReaderForFile($tempPath);
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $reader->setReadFilter(new class implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row === 1;
                }
            });

            $spreadsheet = $reader->load($tempPath);
            $sheetCount = $spreadsheet->getSheetCount();

            if ($sheetCount === 0) {
                throw new RuntimeException(
                    sprintf('El archivo "%s" no contiene hojas.', $file->original_name)
                );
            }

            Log::info('Validando archivo Excel', [
                'file_id' => $file->id,
                'file_name' => $file->original_name,
                'sheet_count' => $sheetCount,
            ]);

            $sheetsValidated = 0;
            $sheetsWithErrors = [];

            for ($sheetIndex = 0; $sheetIndex < $sheetCount; $sheetIndex++) {
                $worksheet = $spreadsheet->getSheet($sheetIndex);
                $sheetName = $worksheet->getTitle();

                try {
                    $this->validateExcelSheet($worksheet, $expectedColumns, $sheetName, $file->original_name);
                    $sheetsValidated++;

                    Log::debug('Hoja de Excel validada', [
                        'file_id' => $file->id,
                        'sheet_index' => $sheetIndex,
                        'sheet_name' => $sheetName,
                    ]);
                } catch (RuntimeException $exception) {
                    $sheetsWithErrors[] = sprintf(
                        'Hoja "%s": %s',
                        $sheetName,
                        $exception->getMessage()
                    );
                }
            }

            if ($sheetsWithErrors !== []) {
                throw new RuntimeException(
                    sprintf(
                        'El archivo "%s" tiene hojas con errores de estructura: %s',
                        $file->original_name,
                        implode(' | ', $sheetsWithErrors)
                    )
                );
            }

            Log::info('Todas las hojas del archivo Excel validadas exitosamente', [
                'file_id' => $file->id,
                'file_name' => $file->original_name,
                'sheets_validated' => $sheetsValidated,
            ]);
        } catch (ReaderException $exception) {
            throw new RuntimeException(
                sprintf(
                    'Error al leer el archivo Excel "%s": %s',
                    $file->original_name,
                    $exception->getMessage()
                ),
                previous: $exception
            );
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * Valida una hoja individual de Excel.
     *
     * @param array<int, string> $expectedColumns
     *
     * @throws RuntimeException
     */
    private function validateExcelSheet(
        Worksheet $worksheet,
        array $expectedColumns,
        string $sheetName,
        string $fileName
    ): void {
        // Verificar que la hoja tenga datos
        if ($worksheet->getHighestRow() < 1) {
            throw new RuntimeException(
                sprintf('La hoja "%s" está vacía', $sheetName)
            );
        }

        // Obtener primera fila (encabezados) de forma eficiente
        $headers = [];
        $highestColumn = $worksheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $cellValue = $worksheet->getCellByColumnAndRow($col, 1)->getValue();
            $header = trim((string) $cellValue);

            // Solo agregar columnas no vacías
            if ($header !== '') {
                $headers[] = $header;
            }
        }

        if ($headers === []) {
            throw new RuntimeException(
                sprintf('La hoja "%s" no tiene encabezados', $sheetName)
            );
        }

        // Validar columnas
        $this->validateColumns($headers, $expectedColumns, sprintf('%s [%s]', $fileName, $sheetName));
    }

    /**
     * Valida que las columnas del archivo coincidan con las esperadas.
     *
     * @param array<int, string> $actualColumns Columnas del archivo
     * @param array<int, string> $expectedColumns Columnas esperadas
     *
     * @throws RuntimeException Si las columnas no coinciden
     */
    private function validateColumns(
        array $actualColumns,
        array $expectedColumns,
        string $fileName
    ): void {
        // Normalizar columnas esperadas
        $normalizedExpected = array_map(
            fn (string $col): string => trim($col),
            $expectedColumns
        );

        // Remover columnas vacías del archivo
        $actualColumns = array_filter(
            $actualColumns,
            fn (string $col): bool => $col !== ''
        );

        // Comparar columnas (orden no importa)
        $missingColumns = array_diff($normalizedExpected, $actualColumns);
        $extraColumns = array_diff($actualColumns, $normalizedExpected);

        $errors = [];

        if ($missingColumns !== []) {
            $errors[] = sprintf(
                'Faltan %d columna(s): %s',
                count($missingColumns),
                implode(', ', array_values($missingColumns))
            );
        }

        if ($extraColumns !== []) {
            $errors[] = sprintf(
                'Contiene %d columna(s) adicional(es): %s',
                count($extraColumns),
                implode(', ', array_values($extraColumns))
            );
        }

        if ($errors !== []) {
            throw new RuntimeException(
                sprintf(
                    'La estructura del archivo no coincide con el insumo esperado. %s',
                    implode('. ', $errors)
                )
            );
        }
    }
}