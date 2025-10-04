<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\DTOs\Recaudo\Comunicados\ProcessingContextDto;
use App\Services\Recaudo\PostgreSQLCopyImporter;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Paso 3: Carga los CSVs generados por Go a PostgreSQL usando COPY.
 *
 * Lee los CSVs generados en el paso anterior (ConvertExcelToCSVStep)
 * y los importa a sus respectivas tablas usando PostgreSQL COPY nativo.
 *
 * Performance esperada:
 * - 10-50x más rápido que inserts por chunks
 * - CSV 100MB: ~3 segundos (vs ~30s con chunks)
 */
final readonly class LoadExcelCSVsStep implements ProcessingStepInterface
{
    /**
     * Mapeo de códigos de data source a tablas PostgreSQL.
     */
    private const TABLE_MAP = [
        'DETTRA' => 'data_source_dettra',
        'PAGAPL' => 'data_source_pagapl',
        'PAGPLA' => 'data_source_pagpla',
    ];

    /**
     * Mapeo de data sources a sus columnas en la tabla.
     * IMPORTANTE: El orden debe coincidir con el orden de columnas en el CSV transformado.
     */
    private const COLUMN_MAP = [
        'DETTRA' => [
            'run_id',
            'data',
            'sheet_name',
        ],
        'PAGAPL' => [
            'run_id',
            'identificacion',
            'periodo',
            'valor',
            'composite_key',
            'data',
            'sheet_name',
        ],
        'PAGPLA' => [
            'run_id',
            'data',
            'sheet_name',
        ],
    ];

    /**
     * Data sources que necesitan transformación CSV→JSON antes de COPY.
     * Estos tienen todas las columnas del Excel en el CSV, pero la tabla solo acepta data (jsonb).
     */
    private const NEEDS_TRANSFORMATION = ['DETTRA', 'PAGPLA'];

    public function __construct(
        private FilesystemFactory $filesystem,
        private PostgreSQLCopyImporter $copyImporter
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
        $disk = $this->filesystem->disk('collection');

        Log::info('📥 Iniciando carga de CSVs a PostgreSQL con COPY', [
            'step' => self::class,
            'run_id' => $run->id,
        ]);

        $stepStartTime = microtime(true);
        $totalRowsImported = 0;
        $totalSheetsImported = 0;

        foreach (self::TABLE_MAP as $dataSourceCode => $tableName) {
            // Obtener información del contexto (generada por ConvertExcelToCSVStep)
            $dataSourceInfo = $context->getData($dataSourceCode);

            if ($dataSourceInfo === null) {
                Log::info('Data source no encontrado en contexto, omitiendo', [
                    'data_source' => $dataSourceCode,
                ]);
                continue;
            }

            $csvOutputDir = $dataSourceInfo['csv_output_dir'] ?? null;
            $sheets = $dataSourceInfo['sheets'] ?? [];

            if ($csvOutputDir === null || empty($sheets)) {
                Log::warning('Información de CSVs incompleta', [
                    'data_source' => $dataSourceCode,
                    'csv_output_dir' => $csvOutputDir,
                    'sheets_count' => count($sheets),
                ]);
                continue;
            }

            Log::info('📂 Cargando CSVs de data source con PostgreSQL COPY', [
                'run_id' => $run->id,
                'data_source' => $dataSourceCode,
                'table' => $tableName,
                'sheets_count' => count($sheets),
            ]);

            // Obtener columnas de la tabla
            $columns = $this->getTableColumns($tableName, $dataSourceCode);

            // Importar cada CSV (cada hoja)
            foreach ($sheets as $sheetName => $sheetInfo) {
                $csvPath = $disk->path($sheetInfo['path']);

                if (!file_exists($csvPath)) {
                    throw new RuntimeException(
                        sprintf('CSV no encontrado: %s', $csvPath)
                    );
                }

                // Transformar CSV si es necesario (DETTRA, PAGPLA)
                $finalCsvPath = $csvPath;
                if (in_array($dataSourceCode, self::NEEDS_TRANSFORMATION, true)) {
                    Log::info('🔄 Transformando CSV (columnas→JSON) antes de COPY', [
                        'run_id' => $run->id,
                        'data_source' => $dataSourceCode,
                        'sheet_name' => $sheetName,
                    ]);

                    $finalCsvPath = $this->transformCsvToJsonFormat(
                        $csvPath,
                        $run->id,
                        $sheetName
                    );
                }

                Log::info('📄 Importando CSV con COPY', [
                    'run_id' => $run->id,
                    'data_source' => $dataSourceCode,
                    'table' => $tableName,
                    'sheet_name' => $sheetName,
                    'csv_path' => $finalCsvPath,
                    'expected_rows' => $sheetInfo['rows'] ?? 0,
                ]);

                $importStart = microtime(true);

                // Importar con PostgreSQL COPY
                $result = $this->copyImporter->importFromFile(
                    $tableName,
                    $finalCsvPath,
                    $columns,
                    ';', // Delimitador
                    true // Tiene header
                );

                $importDuration = (int) ((microtime(true) - $importStart) * 1000);

                Log::info('✅ CSV importado con COPY', [
                    'run_id' => $run->id,
                    'data_source' => $dataSourceCode,
                    'table' => $tableName,
                    'sheet_name' => $sheetName,
                    'rows_imported' => $result['rows'],
                    'duration_ms' => $importDuration,
                    'rows_per_second' => $importDuration > 0
                        ? round($result['rows'] / ($importDuration / 1000))
                        : 0,
                ]);

                $totalRowsImported += $result['rows'];
                $totalSheetsImported++;
            }

            // Actualizar contexto con información de carga
            $context = $context->addData($dataSourceCode, [
                ...$dataSourceInfo,
                'loaded_to_db' => true,
                'table_name' => $tableName,
                'rows_imported' => $totalRowsImported,
            ]);
        }

        $stepDuration = (int) ((microtime(true) - $stepStartTime) * 1000);

        Log::info('🎉 Carga de CSVs completada con PostgreSQL COPY', [
            'step' => self::class,
            'run_id' => $run->id,
            'total_sheets_imported' => $totalSheetsImported,
            'total_rows_imported' => number_format($totalRowsImported),
            'duration_ms' => $stepDuration,
            'duration_sec' => round($stepDuration / 1000, 2),
            'avg_rows_per_second' => $stepDuration > 0
                ? round($totalRowsImported / ($stepDuration / 1000))
                : 0,
        ]);

        return $context->addStepResult($this->getName(), [
            'sheets_imported' => $totalSheetsImported,
            'rows_imported' => $totalRowsImported,
            'duration_ms' => $stepDuration,
        ]);
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'Cargar CSVs con PostgreSQL COPY';
    }

    /**
     * @param ProcessingContextDto $context
     *
     * @return bool
     */
    public function shouldExecute(ProcessingContextDto $context): bool
    {
        // Solo ejecutar si hay data sources Excel que se hayan convertido
        foreach (array_keys(self::TABLE_MAP) as $dataSourceCode) {
            $dataSourceInfo = $context->getData($dataSourceCode);
            if ($dataSourceInfo !== null && isset($dataSourceInfo['csv_output_dir'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Obtiene las columnas de la tabla para el data source.
     *
     * @param string $tableName
     * @param string $dataSourceCode
     *
     * @return array<string>
     */
    private function getTableColumns(string $tableName, string $dataSourceCode): array
    {
        // Usar mapeo predefinido si existe
        if (isset(self::COLUMN_MAP[$dataSourceCode])) {
            return self::COLUMN_MAP[$dataSourceCode];
        }

        // Fallback: obtener columnas de la base de datos (excluyendo id y created_at)
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

    /**
     * Transforma un CSV con columnas separadas a formato run_id;data(json);sheet_name.
     *
     * @param string $originalCsvPath Ruta del CSV original con todas las columnas
     * @param int $runId Run ID para agregar a cada fila
     * @param string $sheetName Nombre de la hoja
     *
     * @return string Ruta del CSV transformado
     *
     * @throws RuntimeException
     */
    private function transformCsvToJsonFormat(
        string $originalCsvPath,
        int $runId,
        string $sheetName
    ): string {
        $transformedCsvPath = $originalCsvPath . '.transformed.csv';

        $input = fopen($originalCsvPath, 'r');
        if ($input === false) {
            throw new RuntimeException('No se pudo abrir CSV para transformación: ' . $originalCsvPath);
        }

        $output = fopen($transformedCsvPath, 'w');
        if ($output === false) {
            fclose($input);
            throw new RuntimeException('No se pudo crear CSV transformado: ' . $transformedCsvPath);
        }

        // Escribir header del CSV transformado (sin usar fputcsv para evitar backslashes)
        fwrite($output, "run_id;data;sheet_name\n");

        // Leer header del CSV original
        $headerLine = fgets($input);
        if ($headerLine === false) {
            fclose($input);
            fclose($output);
            throw new RuntimeException('CSV sin header: ' . $originalCsvPath);
        }
        $headers = str_getcsv(trim($headerLine), ';', '"', '');

        // Procesar cada fila
        $rowsProcessed = 0;
        $rowsWithWarnings = [];
        $currentLine = 1; // +1 por el header

        while (($line = fgets($input)) !== false) {
            $currentLine++;

            // Usar str_getcsv con escape deshabilitado para evitar problemas con backslashes
            $row = str_getcsv(trim($line), ';', '"', '');

            // Skip filas vacías (todas las columnas son null o string vacío)
            // También skip si solo tiene una columna vacía (línea solo con delimitadores)
            $hasData = false;
            foreach ($row as $value) {
                // Considerar solo valores que no sean null, no sean empty string, y no sean solo whitespace
                if ($value !== null && trim($value) !== '') {
                    $hasData = true;
                    break;
                }
            }
            if (!$hasData) {
                continue; // Saltar fila vacía
            }

            $hadWarning = false;

            // Crear objeto JSON con todas las columnas
            $jsonData = [];
            foreach ($headers as $index => $header) {
                $value = $row[$index] ?? null;

                // Sanitizar backslashes que causan problemas con PostgreSQL COPY
                if ($value !== null && is_string($value)) {
                    $originalValue = $value;
                    // Reemplazar backslashes solitarios con espacio
                    $value = str_replace('\\', ' ', $value);

                    if ($originalValue !== $value) {
                        $hadWarning = true;
                    }
                }

                $jsonData[$header] = $value;
            }

            if ($hadWarning) {
                $rowsWithWarnings[] = $currentLine;
            }

            // Escribir CSV manualmente para evitar que fputcsv() agregue backslashes
            $outputRow = [
                $runId,
                json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $sheetName,
            ];

            $csvLine = implode(';', array_map(function($value, $index) {
                if ($value === null || $value === '') {
                    return '';
                }
                // Convertir a string si es necesario
                $stringValue = (string) $value;

                // SIEMPRE poner el campo JSON entre quotes (índice 1 es el data)
                if ($index === 1) {
                    // JSON siempre va entre quotes, escapando comillas dobles
                    return '"' . str_replace('"', '""', $stringValue) . '"';
                }

                // Para otros campos, solo agregar quotes si contiene delimitador o saltos de línea
                if (strpos($stringValue, ';') !== false || strpos($stringValue, "\n") !== false || strpos($stringValue, "\r") !== false) {
                    return '"' . str_replace('"', '""', $stringValue) . '"';
                }
                return $stringValue;
            }, $outputRow, array_keys($outputRow)));

            fwrite($output, $csvLine . "\n");
            $rowsProcessed++;
        }

        fclose($input);
        fclose($output);

        $logData = [
            'original' => basename($originalCsvPath),
            'transformed' => basename($transformedCsvPath),
            'rows_processed' => $rowsProcessed,
        ];

        if (!empty($rowsWithWarnings)) {
            $logData['rows_with_backslash_warnings'] = count($rowsWithWarnings);
            $logData['sample_warning_lines'] = array_slice($rowsWithWarnings, 0, 10);

            Log::warning('⚠️  CSV transformado con advertencias: backslashes reemplazados', $logData);
        } else {
            Log::info('✅ CSV transformado sin advertencias', $logData);
        }

        return $transformedCsvPath;
    }
}
