<?php

declare(strict_types=1);

namespace App\Services\CollectionRun\Validators;

use App\Models\CollectionNoticeRunFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use XMLReader;
use ZipArchive;

/**
 * Validador de estructura de archivos CSV/Excel con soporte para:
 * - Lectura por chunks (grandes volúmenes)
 * - Múltiples hojas en Excel
 * - Validación de estructura sin cargar todo en memoria
 * - Validación de XLSX mediante XMLReader (rápido, sin PhpSpreadsheet)
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
     * - XLSX: Usa XMLReader (rápido, sin PhpSpreadsheet)
     * - XLS: Validación simplificada (formato legacy)
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
        $extension = strtolower($file->ext ?? '');

        // Para archivos XLSX: SIEMPRE usar XMLReader (rápido, sin PhpSpreadsheet)
        if ($extension === 'xlsx') {
            Log::info('Validando archivo XLSX con XMLReader', [
                'file_id' => $file->id,
                'file_name' => $file->original_name,
                'size_mb' => round($file->size / 1024 / 1024, 2),
            ]);

            $this->validateXlsxWithXmlReader($disk, $file, $expectedColumns);
            return;
        }

        // Para archivos XLS (formato legacy): validación simplificada
        if ($extension === 'xls') {
            Log::warning('Archivo XLS detectado, validación simplificada (formato legacy)', [
                'file_id' => $file->id,
                'file_name' => $file->original_name,
                'size_mb' => round($file->size / 1024 / 1024, 2),
            ]);

            // Solo verificar que el archivo existe y es legible
            $stream = $disk->readStream($file->path);
            if ($stream === false) {
                throw new RuntimeException(
                    sprintf('No se pudo leer el archivo "%s"', $file->original_name)
                );
            }

            if (is_resource($stream)) {
                fclose($stream);
            }

            Log::info('Archivo XLS validado (verificación básica de lectura)', [
                'file_id' => $file->id,
                'file_name' => $file->original_name,
            ]);

            return;
        }

        // Formato no soportado
        throw new RuntimeException(
            sprintf('Formato de Excel no soportado: %s', $extension)
        );
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

    /**
     * Valida un archivo XLSX usando XMLReader (sin PhpSpreadsheet).
     *
     * XLSX es un archivo ZIP que contiene XMLs. Esta función:
     * 1. Descomprime el archivo temporalmente
     * 2. Lee xl/workbook.xml para obtener nombres de hojas
     * 3. Lee xl/worksheets/sheetN.xml para extraer headers (fila 1)
     * 4. Valida headers contra columnas esperadas
     *
     * Ventajas sobre PhpSpreadsheet:
     * - Mucho más rápido (no carga el archivo completo en memoria)
     * - Consume menos memoria
     * - No se bloquea con archivos grandes
     *
     * @param array<int, string> $expectedColumns
     *
     * @throws RuntimeException
     */
    private function validateXlsxWithXmlReader(
        Filesystem $disk,
        CollectionNoticeRunFile $file,
        array $expectedColumns
    ): void {
        $tempZipPath = sys_get_temp_dir() . '/' . uniqid('xlsx_validation_', true) . '.zip';
        $extractPath = sys_get_temp_dir() . '/' . uniqid('xlsx_extract_', true);

        try {
            // Descargar archivo a temporal
            $stream = $disk->readStream($file->path);
            if ($stream === false) {
                throw new RuntimeException(
                    sprintf('No se pudo leer el archivo "%s"', $file->original_name)
                );
            }

            file_put_contents($tempZipPath, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            // Abrir ZIP
            $zip = new ZipArchive();
            if ($zip->open($tempZipPath) !== true) {
                throw new RuntimeException(
                    sprintf('El archivo "%s" no es un XLSX válido (no se pudo abrir como ZIP)', $file->original_name)
                );
            }

            // Extraer a directorio temporal
            if (!$zip->extractTo($extractPath)) {
                $zip->close();
                throw new RuntimeException(
                    sprintf('No se pudo extraer el archivo XLSX "%s"', $file->original_name)
                );
            }
            $zip->close();

            // Leer nombres de hojas desde xl/workbook.xml
            $sheetNames = $this->extractSheetNamesFromWorkbook($extractPath, $file->original_name);

            if ($sheetNames === []) {
                throw new RuntimeException(
                    sprintf('El archivo "%s" no contiene hojas', $file->original_name)
                );
            }

            Log::info('Iniciando validación de XLSX con XMLReader', [
                'file_id' => $file->id,
                'file_name' => $file->original_name,
                'sheet_count' => count($sheetNames),
                'size_mb' => round($file->size / 1024 / 1024, 2),
            ]);

            // Leer shared strings (si existe)
            $sharedStrings = $this->loadSharedStrings($extractPath);

            Log::debug('Shared strings cargados', [
                'file_id' => $file->id,
                'shared_strings_count' => count($sharedStrings),
            ]);

            // Validar cada hoja
            $sheetsValidated = 0;
            $sheetsWithErrors = [];

            foreach ($sheetNames as $index => $sheetName) {
                $sheetFile = $extractPath . '/xl/worksheets/sheet' . ($index + 1) . '.xml';

                if (!file_exists($sheetFile)) {
                    $sheetsWithErrors[] = sprintf('Hoja "%s": archivo XML no encontrado', $sheetName);
                    continue;
                }

                try {
                    $headers = $this->extractHeadersFromSheet($sheetFile, $sharedStrings);
                    $this->validateColumns($headers, $expectedColumns, sprintf('%s [%s]', $file->original_name, $sheetName));
                    $sheetsValidated++;

                    Log::debug('Hoja XLSX validada correctamente', [
                        'file_id' => $file->id,
                        'sheet_index' => $index,
                        'sheet_name' => $sheetName,
                        'headers_count' => count($headers),
                    ]);
                } catch (RuntimeException $exception) {
                    $sheetsWithErrors[] = sprintf('Hoja "%s": %s', $sheetName, $exception->getMessage());
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

            Log::info('Archivo XLSX validado exitosamente con XMLReader', [
                'file_id' => $file->id,
                'file_name' => $file->original_name,
                'sheets_validated' => $sheetsValidated,
                'size_mb' => round($file->size / 1024 / 1024, 2),
            ]);
        } finally {
            // Limpiar archivos temporales
            if (file_exists($tempZipPath)) {
                unlink($tempZipPath);
            }

            if (is_dir($extractPath)) {
                $this->recursiveRemoveDirectory($extractPath);
            }
        }
    }

    /**
     * Extrae nombres de hojas desde xl/workbook.xml.
     *
     * @return array<int, string>
     */
    private function extractSheetNamesFromWorkbook(string $extractPath, string $fileName): array
    {
        $workbookPath = $extractPath . '/xl/workbook.xml';

        if (!file_exists($workbookPath)) {
            throw new RuntimeException(
                sprintf('El archivo "%s" no contiene xl/workbook.xml', $fileName)
            );
        }

        $xml = simplexml_load_file($workbookPath);
        if ($xml === false) {
            throw new RuntimeException(
                sprintf('No se pudo parsear xl/workbook.xml del archivo "%s"', $fileName)
            );
        }

        $sheetNames = [];
        $sheets = $xml->sheets->sheet ?? [];

        foreach ($sheets as $sheet) {
            $name = (string) ($sheet['name'] ?? '');
            if ($name !== '') {
                $sheetNames[] = $name;
            }
        }

        return $sheetNames;
    }

    /**
     * Carga shared strings desde xl/sharedStrings.xml (si existe).
     *
     * @return array<int, string>
     */
    private function loadSharedStrings(string $extractPath): array
    {
        $sharedStringsPath = $extractPath . '/xl/sharedStrings.xml';

        if (!file_exists($sharedStringsPath)) {
            return []; // No hay shared strings
        }

        $xml = simplexml_load_file($sharedStringsPath);
        if ($xml === false) {
            return [];
        }

        $strings = [];
        foreach ($xml->si as $si) {
            $strings[] = (string) ($si->t ?? '');
        }

        return $strings;
    }

    /**
     * Extrae headers (primera fila) de un archivo sheet XML usando XMLReader.
     *
     * Lee solo la primera fila del archivo para extraer los encabezados de columnas.
     * Maneja shared strings correctamente.
     *
     * @param string $sheetFile Ruta al archivo sheet XML
     * @param array<int, string> $sharedStrings Array de shared strings
     *
     * @return array<int, string> Headers de la primera fila
     *
     * @throws RuntimeException
     */
    private function extractHeadersFromSheet(string $sheetFile, array $sharedStrings): array
    {
        $reader = new XMLReader();
        if (!$reader->open($sheetFile)) {
            throw new RuntimeException('No se pudo abrir el archivo sheet XML');
        }

        $headers = [];
        $inFirstRow = false;

        try {
            while ($reader->read()) {
                // Detectar inicio de fila
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'row') {
                    $rowNum = $reader->getAttribute('r');
                    if ($rowNum === '1') {
                        $inFirstRow = true;
                    }
                }

                // Leer celdas de la primera fila
                if ($inFirstRow && $reader->nodeType === XMLReader::ELEMENT && $reader->name === 'c') {
                    $cellType = $reader->getAttribute('t');
                    $cellValue = '';

                    // Leer contenido de la celda
                    if ($reader->read() && $reader->name === 'v') {
                        $cellValue = $reader->readString();

                        // Si es tipo 's', es referencia a sharedStrings
                        if ($cellType === 's' && isset($sharedStrings[(int) $cellValue])) {
                            $cellValue = $sharedStrings[(int) $cellValue];
                        }
                    }

                    $headers[] = trim($cellValue);
                }

                // Finalizar lectura cuando termina la primera fila
                if ($inFirstRow && $reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'row') {
                    break; // Ya procesamos la primera fila
                }
            }
        } finally {
            $reader->close();
        }

        // Filtrar headers vacíos
        $filteredHeaders = array_filter($headers, fn($h) => $h !== '');

        if ($filteredHeaders === []) {
            throw new RuntimeException('La hoja no contiene encabezados en la primera fila');
        }

        return $filteredHeaders;
    }

    /**
     * Elimina recursivamente un directorio y su contenido.
     */
    private function recursiveRemoveDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = array_diff(scandir($directory) ?: [], ['.', '..']);

        foreach ($items as $item) {
            $path = $directory . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->recursiveRemoveDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}