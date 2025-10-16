<?php

declare(strict_types=1);

namespace App\Services\Recaudo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Servicio para importar CSVs de forma resiliente línea por línea.
 *
 * A diferencia de PostgreSQLCopyImporter que usa COPY (todo o nada),
 * este servicio procesa por chunks con manejo granular de errores,
 * registrando líneas fallidas sin detener el proceso.
 */
final class ResilientCsvImporter
{
    /**
     * Tamaño de chunk para procesar (balance entre performance y memoria).
     */
    private const CHUNK_SIZE = 1000;

    /**
     * Tamaño máximo de contenido de línea a guardar en log (para evitar bloat).
     */
    private const MAX_LINE_CONTENT_LENGTH = 500;

    /**
     * Importa un CSV de forma resiliente, línea por línea con chunks.
     *
     * @param string $tableName Nombre de la tabla destino
     * @param string $csvPath Ruta absoluta al archivo CSV
     * @param array<string> $columns Lista de columnas en el CSV (en orden)
     * @param int $runId ID del run para asociar errores
     * @param string $dataSourceCode Código del data source (BASCAR, BAPRPO, etc)
     * @param string $delimiter Delimitador del CSV
     * @param bool $hasHeader Si el CSV tiene header
     *
     * @return array{
     *     total_rows: int,
     *     success_rows: int,
     *     error_rows: int,
     *     duration_ms: int,
     *     errors_logged: int
     * }
     */
    public function importFromFile(
        string $tableName,
        string $csvPath,
        array $columns,
        int $runId,
        string $dataSourceCode,
        string $delimiter = ';',
        bool $hasHeader = true
    ): array {
        $startTime = microtime(true);

        if (!file_exists($csvPath)) {
            throw new RuntimeException("Archivo CSV no encontrado: {$csvPath}");
        }

        $fileSize = filesize($csvPath);

        Log::info('Iniciando importación resiliente de CSV', [
            'table' => $tableName,
            'csv_path' => $csvPath,
            'run_id' => $runId,
            'data_source' => $dataSourceCode,
            'file_size_mb' => round($fileSize / 1024 / 1024, 2),
            'chunk_size' => self::CHUNK_SIZE,
        ]);

        // Convertir de Latin1 a UTF-8 si es necesario
        $csvPath = $this->ensureUtf8Encoding($csvPath, $dataSourceCode);

        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            throw new RuntimeException("No se pudo abrir el archivo CSV: {$csvPath}");
        }

        $totalRows = 0;
        $successRows = 0;
        $errorRows = 0;
        $errorsLogged = 0;
        $currentLine = 0;
        $chunk = [];
        $chunksProcessed = 0;

        try {
            // Saltar header si existe
            if ($hasHeader) {
                fgets($handle);
                $currentLine++;
                Log::info('Header del CSV skippeado', ['current_line' => $currentLine]);
            }

            Log::info('Iniciando lectura línea por línea del CSV');

            while (($line = fgets($handle)) !== false) {
                $currentLine++;
                $totalRows++;

                // Parsear línea CSV
                $parsedData = str_getcsv($line, $delimiter);

                // Validar que tenga el número correcto de columnas
                if (count($parsedData) !== count($columns)) {
                    $this->logError(
                        $runId,
                        $dataSourceCode,
                        $tableName,
                        $currentLine,
                        $line,
                        'column_mismatch',
                        sprintf(
                            'Esperadas %d columnas, encontradas %d',
                            count($columns),
                            count($parsedData)
                        )
                    );
                    $errorRows++;
                    $errorsLogged++;
                    continue;
                }

                // Preparar datos para inserción
                $rowData = array_combine($columns, $parsedData);
                $rowData['run_id'] = $runId;
                $rowData['created_at'] = now();

                // Agregar a chunk (sin line_content para ahorrar memoria)
                $chunk[] = [
                    'data' => $rowData,
                    'line_number' => $currentLine,
                ];

                // Procesar chunk cuando alcanza el tamaño
                if (count($chunk) >= self::CHUNK_SIZE) {
                    $chunksProcessed++;

                    // Log cada 25 chunks (~25,000 registros) para reducir ruido
                    if ($chunksProcessed % 25 === 0) {
                        Log::info('CSV Batch - progreso', [
                            'chunks' => $chunksProcessed,
                            'filas_ok' => number_format($successRows),
                            'filas_error' => number_format($errorRows),
                        ]);
                    }

                    $result = $this->processChunk($tableName, $chunk, $runId, $dataSourceCode);
                    $successRows += $result['success'];
                    $errorRows += $result['errors'];
                    $errorsLogged += $result['errors_logged'];

                    // Liberar memoria explícitamente
                    unset($chunk, $result);
                    $chunk = [];
                    gc_collect_cycles();
                }
            }

            // Procesar chunk restante
            if (count($chunk) > 0) {
                $chunksProcessed++;
                $result = $this->processChunk($tableName, $chunk, $runId, $dataSourceCode);
                $successRows += $result['success'];
                $errorRows += $result['errors'];
                $errorsLogged += $result['errors_logged'];

                // Liberar memoria del último chunk
                unset($chunk, $result);
                gc_collect_cycles();
            }
        } finally {
            fclose($handle);

            // Limpiar archivo UTF-8 temporal si se creó
            if (str_ends_with($csvPath, '.utf8.csv') && file_exists($csvPath)) {
                unlink($csvPath);
                Log::info('Archivo UTF-8 temporal eliminado', [
                    'path' => $csvPath,
                ]);
            }
        }

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('Importación resiliente completada', [
            'table' => $tableName,
            'run_id' => $runId,
            'data_source' => $dataSourceCode,
            'chunks_processed' => $chunksProcessed,
            'total_rows' => $totalRows,
            'success_rows' => $successRows,
            'error_rows' => $errorRows,
            'errors_logged' => $errorsLogged,
            'duration_ms' => $duration,
            'success_rate' => $totalRows > 0 ? round(($successRows / $totalRows) * 100, 2) : 0,
        ]);

        return [
            'total_rows' => $totalRows,
            'success_rows' => $successRows,
            'error_rows' => $errorRows,
            'duration_ms' => $duration,
            'errors_logged' => $errorsLogged,
        ];
    }

    /**
     * Procesa un chunk de líneas usando batch insert.
     *
     * Intenta insertar todo el chunk en una transacción.
     * Si falla, hace fallback a inserción línea por línea para identificar
     * qué filas específicas tienen errores.
     *
     * @param string $tableName
     * @param array $chunk
     * @param int $runId
     * @param string $dataSourceCode
     *
     * @return array{success: int, errors: int, errors_logged: int}
     */
    private function processChunk(
        string $tableName,
        array $chunk,
        int $runId,
        string $dataSourceCode
    ): array {
        $success = 0;
        $errors = 0;
        $errorsLogged = 0;

        // Extraer solo los datos para batch insert
        $batchData = array_column($chunk, 'data');

        // ESTRATEGIA 1: Intentar batch insert completo (más rápido)
        DB::beginTransaction();
        try {
            DB::table($tableName)->insert($batchData);
            DB::commit();
            $success = count($chunk);

            return [
                'success' => $success,
                'errors' => 0,
                'errors_logged' => 0,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();

            // ESTRATEGIA 2: Si falla el batch, procesar línea por línea
            // para identificar exactamente qué filas tienen problemas
            Log::warning('Batch insert falló, procesando línea por línea', [
                'table' => $tableName,
                'chunk_size' => count($chunk),
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback: Procesar línea por línea SIN transacción individual (más rápido)
        // No usamos transacciones individuales porque el volumen de inserts es alto
        // y el overhead de 10k transacciones es prohibitivo
        foreach ($chunk as $item) {
            try {
                DB::table($tableName)->insert($item['data']);
                $success++;
            } catch (\Throwable $e) {
                $this->logError(
                    $runId,
                    $dataSourceCode,
                    $tableName,
                    $item['line_number'],
                    '', // No tenemos line_content en memoria (optimización)
                    'insert_error',
                    $e->getMessage()
                );
                $errors++;
                $errorsLogged++;
            }
        }

        return [
            'success' => $success,
            'errors' => $errors,
            'errors_logged' => $errorsLogged,
        ];
    }

    /**
     * Registra un error de importación en la base de datos.
     *
     * Usa una nueva conexión de BD para evitar problemas con transacciones
     * abortadas en PostgreSQL.
     */
    private function logError(
        int $runId,
        string $dataSourceCode,
        string $tableName,
        int $lineNumber,
        string $lineContent,
        string $errorType,
        string $errorMessage
    ): void {
        try {
            // Usar una nueva transacción independiente para el log de errores
            // Esto evita que una transacción abortada bloquee el registro de errores
            DB::beginTransaction();
            try {
                DB::table('csv_import_error_logs')->insert([
                    'run_id' => $runId,
                    'data_source_code' => $dataSourceCode,
                    'table_name' => $tableName,
                    'line_number' => $lineNumber,
                    'line_content' => mb_substr($lineContent, 0, self::MAX_LINE_CONTENT_LENGTH),
                    'error_type' => $errorType,
                    'error_message' => mb_substr($errorMessage, 0, 1000),
                    'created_at' => now(),
                ]);
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Throwable $e) {
            // Fallback a logs si falla el insert
            Log::error('No se pudo registrar error de CSV en BD', [
                'run_id' => $runId,
                'line' => $lineNumber,
                'error_type' => $errorType,
                'error_msg' => mb_substr($errorMessage, 0, 200),
                'db_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Asegura que el CSV esté en UTF-8, convirtiendo desde Latin1 si es necesario.
     *
     * @param string $csvPath Ruta al CSV original
     * @param string $dataSourceCode Código del data source
     * @return string Ruta al CSV (original o convertido)
     */
    private function ensureUtf8Encoding(string $csvPath, string $dataSourceCode): string
    {
        // Leer primeras líneas para detectar encoding
        $sample = file_get_contents($csvPath, false, null, 0, 8192);

        // Detectar si tiene caracteres Latin1/ISO-8859-1
        $isUtf8 = mb_check_encoding($sample, 'UTF-8');

        if ($isUtf8) {
            Log::info('CSV ya está en UTF-8, no requiere conversión', [
                'data_source' => $dataSourceCode,
                'path' => $csvPath,
            ]);
            return $csvPath;
        }

        // Convertir de Latin1 a UTF-8
        Log::warning('CSV detectado con codificación Latin1, convirtiendo a UTF-8', [
            'data_source' => $dataSourceCode,
            'original_path' => $csvPath,
        ]);

        $outputPath = $csvPath . '.utf8.csv';
        $input = fopen($csvPath, 'r');
        $output = fopen($outputPath, 'w');

        $linesConverted = 0;
        while (($line = fgets($input)) !== false) {
            // Convertir de ISO-8859-1 (Latin1) a UTF-8
            $utf8Line = mb_convert_encoding($line, 'UTF-8', 'ISO-8859-1');
            fwrite($output, $utf8Line);
            $linesConverted++;
        }

        fclose($input);
        fclose($output);

        Log::info('Conversión UTF-8 completada', [
            'data_source' => $dataSourceCode,
            'lines_converted' => $linesConverted,
            'output_path' => $outputPath,
        ]);

        return $outputPath;
    }
}
