<?php

declare(strict_types=1);

namespace App\Services\Recaudo;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Servicio para importar CSVs a PostgreSQL usando COPY FROM STDIN.
 *
 * Usa el comando COPY nativo de PostgreSQL que es 10-50x más rápido
 * que inserts individuales o chunks.
 */
final class PostgreSQLCopyImporter
{
    /**
     * Importa un CSV a una tabla PostgreSQL usando COPY FROM STDIN.
     *
     * Usa el comando nativo COPY de PostgreSQL que es 10-50x más rápido
     * que inserts individuales o por chunks.
     *
     * @param string $tableName Nombre de la tabla destino
     * @param string $csvPath Ruta absoluta al archivo CSV
     * @param array<string> $columns Lista de columnas en el CSV (en orden)
     * @param string $delimiter Delimitador del CSV
     * @param bool $hasHeader Si el CSV tiene header (se saltará la primera línea)
     *
     * @return array{rows: int, duration_ms: int} Información de la importación
     *
     * @throws RuntimeException
     */
    public function importFromFile(
        string $tableName,
        string $csvPath,
        array $columns,
        string $delimiter = ';',
        bool $hasHeader = true
    ): array {
        $startTime = microtime(true);

        if (!file_exists($csvPath)) {
            throw new RuntimeException("Archivo CSV no encontrado: {$csvPath}");
        }

        $fileSize = filesize($csvPath);

        Log::info('Iniciando importación COPY usando psql CLI', [
            'table' => $tableName,
            'csv_path' => $csvPath,
            'columns' => $columns,
            'file_size_mb' => round($fileSize / 1024 / 1024, 2),
            'has_header' => $hasHeader,
        ]);

        $columnsList = implode(', ', array_map(fn($col) => '"' . $col . '"', $columns));
        $headerOption = $hasHeader ? 'HEADER' : '';

        // Obtener credenciales de la conexión
        $config = config('database.connections.' . config('database.default'));
        $host = $config['host'];
        $port = $config['port'];
        $database = $config['database'];
        $username = $config['username'];
        $password = $config['password'];

        // Construir comando COPY
        $copySQL = sprintf(
            "COPY %s (%s) FROM STDIN WITH (FORMAT csv, DELIMITER '%s', %s, NULL '')",
            $tableName,
            $columnsList,
            $delimiter,
            $headerOption
        );

        // Usar psql con COPY FROM STDIN
        $command = sprintf(
            "PGPASSWORD=%s psql -h %s -p %d -U %s -d %s -c %s < %s 2>&1",
            escapeshellarg($password),
            escapeshellarg($host),
            $port,
            escapeshellarg($username),
            escapeshellarg($database),
            escapeshellarg($copySQL),
            escapeshellarg($csvPath)
        );

        Log::debug('Ejecutando COPY con psql CLI', [
            'command' => preg_replace('/PGPASSWORD=[^ ]+/', 'PGPASSWORD=***', $command),
        ]);

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new RuntimeException(
                "Error al importar CSV con psql COPY: " . implode("\n", $output)
            );
        }

        // Contar filas del CSV para el resultado
        $rowCount = 0;
        $handle = fopen($csvPath, 'r');
        if ($handle) {
            while (fgets($handle) !== false) {
                $rowCount++;
            }
            fclose($handle);
        }

        $actualRows = $hasHeader ? $rowCount - 1 : $rowCount;
        $duration = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('Importación COPY completada', [
            'table' => $tableName,
            'rows_imported' => $actualRows,
            'duration_ms' => $duration,
            'rows_per_second' => $duration > 0 ? round($actualRows / ($duration / 1000)) : 0,
            'mb_per_second' => $duration > 0 ? round(($fileSize / 1024 / 1024) / ($duration / 1000), 2) : 0,
        ]);

        return [
            'rows' => $actualRows,
            'duration_ms' => $duration,
        ];
    }

    /**
     * Importa un CSV a PostgreSQL usando COPY con streaming manual.
     *
     * Versión alternativa que usa pg_put_line para mayor control.
     * Útil cuando el archivo está en storage de Laravel y no en filesystem local.
     *
     * @param string $tableName Nombre de la tabla destino
     * @param Filesystem $disk Disco de Laravel donde está el CSV
     * @param string $csvPath Ruta relativa del CSV en el disco
     * @param array<string> $columns Lista de columnas
     * @param string $delimiter Delimitador del CSV
     * @param bool $hasHeader Si el CSV tiene header
     *
     * @return array{rows: int, duration_ms: int}
     */
    public function importFromStorage(
        string $tableName,
        Filesystem $disk,
        string $csvPath,
        array $columns,
        string $delimiter = ';',
        bool $hasHeader = true
    ): array {
        $startTime = microtime(true);

        if (!$disk->exists($csvPath)) {
            throw new RuntimeException("Archivo CSV no encontrado: {$csvPath}");
        }

        $absolutePath = $disk->path($csvPath);

        Log::info('Importando CSV desde storage usando COPY', [
            'table' => $tableName,
            'csv_path' => $csvPath,
            'absolute_path' => $absolutePath,
        ]);

        // Si el path es local, usar pgsqlCopyFromFile directamente
        if (file_exists($absolutePath)) {
            return $this->importFromFile($tableName, $absolutePath, $columns, $delimiter, $hasHeader);
        }

        // Alternativa: leer stream y usar COPY FROM STDIN
        $stream = $disk->readStream($csvPath);
        if ($stream === false) {
            throw new RuntimeException("No se pudo abrir stream del CSV: {$csvPath}");
        }

        $columnsList = implode(', ', $columns);
        $pdo = DB::connection()->getPdo();

        try {
            $copySQL = sprintf(
                "COPY %s (%s) FROM STDIN WITH (FORMAT csv, DELIMITER '%s', %s)",
                $tableName,
                $columnsList,
                $delimiter,
                $hasHeader ? 'HEADER' : ''
            );

            // Crear archivo temporal
            $tempPath = tempnam(sys_get_temp_dir(), 'csv_import_');
            file_put_contents($tempPath, stream_get_contents($stream));
            fclose($stream);

            $result = $this->importFromFile($tableName, $tempPath, $columns, $delimiter, $hasHeader);

            unlink($tempPath);

            return $result;

        } catch (\Throwable $e) {
            if (isset($tempPath) && file_exists($tempPath)) {
                unlink($tempPath);
            }
            throw new RuntimeException(
                "Error al importar CSV desde storage: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Importa múltiples CSVs (hojas) a una tabla usando COPY.
     *
     * @param string $tableName Nombre de la tabla destino
     * @param array<string, string> $csvPaths Array de [sheetName => csvPath]
     * @param array<string> $columns Columnas del CSV
     * @param int $runId ID del run para filtrar después
     * @param string $delimiter Delimitador CSV
     *
     * @return array{total_rows: int, duration_ms: int, sheets: array}
     */
    public function importMultipleSheets(
        string $tableName,
        array $csvPaths,
        array $columns,
        int $runId,
        string $delimiter = ';'
    ): array {
        $startTime = microtime(true);
        $totalRows = 0;
        $sheets = [];

        Log::info('Iniciando importación multi-hoja con COPY', [
            'table' => $tableName,
            'total_sheets' => count($csvPaths),
            'run_id' => $runId,
        ]);

        foreach ($csvPaths as $sheetName => $csvPath) {
            Log::info('Importando hoja', [
                'sheet_name' => $sheetName,
                'csv_path' => $csvPath,
            ]);

            $result = $this->importFromFile($tableName, $csvPath, $columns, $delimiter, true);

            $sheets[$sheetName] = $result;
            $totalRows += $result['rows'];

            Log::info('Hoja importada', [
                'sheet_name' => $sheetName,
                'rows' => $result['rows'],
                'duration_ms' => $result['duration_ms'],
            ]);
        }

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('Importación multi-hoja completada', [
            'table' => $tableName,
            'total_rows' => $totalRows,
            'total_sheets' => count($sheets),
            'duration_ms' => $duration,
        ]);

        return [
            'total_rows' => $totalRows,
            'duration_ms' => $duration,
            'sheets' => $sheets,
        ];
    }
}
