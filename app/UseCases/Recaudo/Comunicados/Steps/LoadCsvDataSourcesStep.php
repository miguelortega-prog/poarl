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
 * Paso 1: Carga archivos CSV directos (BASCAR, BAPRPO, DATPOL) usando PostgreSQL COPY.
 *
 * Este step reemplaza a LoadDataSourceFilesStep que usaba chunks lentos.
 * Usa PostgreSQL COPY nativo que es 10-50x más rápido.
 *
 * Performance esperada:
 * - CSV 50K filas: ~1-3 segundos (vs ~30-60s con chunks)
 */
final readonly class LoadCsvDataSourcesStep implements ProcessingStepInterface
{
    /**
     * Data sources que son CSV y deben cargarse en este paso.
     */
    private const CSV_DATA_SOURCES = ['BASCAR', 'BAPRPO', 'DATPOL'];

    /**
     * Mapeo de códigos de data source a tablas PostgreSQL.
     */
    private const TABLE_MAP = [
        'BASCAR' => 'data_source_bascar',
        'BAPRPO' => 'data_source_baprpo',
        'DATPOL' => 'data_source_datpol',
    ];

    /**
     * Mapeo de data sources a sus columnas en la tabla.
     * El orden debe coincidir con el orden de columnas en el CSV.
     */
    private const COLUMN_MAP = [
        'BASCAR' => [
            'run_id',
            'num_tomador',
            'fecha_inicio_vig',
            'valor_total_fact',
            'periodo',
            'composite_key',
            'data',
            'cantidad_trabajadores',
            'observacion_trabajadores',
            'sheet_name',
        ],
        'BAPRPO' => [
            'run_id',
            'data',
            'sheet_name',
        ],
        'DATPOL' => [
            'run_id',
            'data',
            'sheet_name',
        ],
    ];

    /**
     * Data sources que necesitan transformación CSV→JSON.
     * BASCAR: Extrae columnas específicas + resto en JSON
     * BAPRPO, DATPOL: Todo en JSON
     */
    private const NEEDS_TRANSFORMATION = ['BASCAR', 'BAPRPO', 'DATPOL'];

    /**
     * Mapeo de columnas CSV a columnas de tabla para extracción.
     * Solo para BASCAR que tiene columnas específicas.
     */
    private const CSV_COLUMN_EXTRACTION = [
        'BASCAR' => [
            'NUM_TOMADOR' => 'num_tomador',
            'FECHA_INICIO_VIG' => 'fecha_inicio_vig',
            'VALOR_TOTAL_FACT' => 'valor_total_fact',
        ],
    ];

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

        Log::info('📥 Iniciando carga de CSVs directos con PostgreSQL COPY', [
            'step' => self::class,
            'run_id' => $run->id,
        ]);

        $stepStartTime = microtime(true);
        $totalRowsImported = 0;
        $filesImported = 0;
        $loadedData = [];

        foreach ($run->files as $file) {
            $dataSourceCode = $file->dataSource->code ?? 'unknown';
            $extension = strtolower($file->ext ?? '');

            // Solo procesar archivos CSV de los data sources especificados
            if (!in_array($dataSourceCode, self::CSV_DATA_SOURCES, true)) {
                // Para Excel, solo guardar metadata (se procesan en otros steps)
                if (in_array($extension, ['xlsx', 'xls'], true)) {
                    $loadedData[$dataSourceCode] = [
                        'file_id' => $file->id,
                        'path' => $file->path,
                        'extension' => $extension,
                        'loaded_to_db' => false,
                        'is_excel' => true,
                    ];
                }
                continue;
            }

            if ($extension !== 'csv') {
                Log::warning('Data source esperado como CSV pero encontrado con otra extensión', [
                    'data_source' => $dataSourceCode,
                    'extension' => $extension,
                    'file_path' => $file->path,
                ]);
                continue;
            }

            if (!$disk->exists($file->path)) {
                throw new RuntimeException(
                    sprintf('Archivo CSV no encontrado: %s', $file->path)
                );
            }

            $tableName = self::TABLE_MAP[$dataSourceCode];
            $csvPath = $disk->path($file->path);

            Log::info('📄 Cargando CSV directo con PostgreSQL COPY', [
                'run_id' => $run->id,
                'data_source' => $dataSourceCode,
                'table' => $tableName,
                'file_path' => $file->path,
            ]);

            $importStart = microtime(true);

            // Transformar CSV si es necesario (BAPRPO, DATPOL)
            $finalCsvPath = $csvPath;
            if (in_array($dataSourceCode, self::NEEDS_TRANSFORMATION, true)) {
                Log::info('🔄 Transformando CSV (columnas→JSON) antes de COPY', [
                    'run_id' => $run->id,
                    'data_source' => $dataSourceCode,
                ]);

                $finalCsvPath = $this->transformCsvToJsonFormat(
                    $csvPath,
                    $run->id,
                    $dataSourceCode
                );
            }

            // Obtener columnas de la tabla
            $columns = $this->getTableColumns($tableName, $dataSourceCode);

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
                'rows_imported' => $result['rows'],
                'duration_ms' => $importDuration,
                'rows_per_second' => $importDuration > 0
                    ? round($result['rows'] / ($importDuration / 1000))
                    : 0,
            ]);

            $totalRowsImported += $result['rows'];
            $filesImported++;

            $loadedData[$dataSourceCode] = [
                'file_id' => $file->id,
                'path' => $file->path,
                'extension' => $extension,
                'loaded_to_db' => true,
                'table_name' => $tableName,
                'rows_imported' => $result['rows'],
            ];
        }

        $stepDuration = (int) ((microtime(true) - $stepStartTime) * 1000);

        Log::info('🎉 Carga de CSVs directos completada con PostgreSQL COPY', [
            'step' => self::class,
            'run_id' => $run->id,
            'files_imported' => $filesImported,
            'total_rows_imported' => number_format($totalRowsImported),
            'duration_ms' => $stepDuration,
            'duration_sec' => round($stepDuration / 1000, 2),
            'avg_rows_per_second' => $stepDuration > 0
                ? round($totalRowsImported / ($stepDuration / 1000))
                : 0,
        ]);

        return $context
            ->withData($loadedData)
            ->addStepResult($this->getName(), [
                'files_imported' => $filesImported,
                'total_rows_imported' => $totalRowsImported,
                'duration_ms' => $stepDuration,
            ]);
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'Cargar CSVs directos con PostgreSQL COPY';
    }

    /**
     * @param ProcessingContextDto $context
     *
     * @return bool
     */
    public function shouldExecute(ProcessingContextDto $context): bool
    {
        // Solo ejecutar si hay archivos CSV que cargar
        $csvFiles = $context->run->files->filter(function ($file) {
            $dataSourceCode = $file->dataSource->code ?? '';
            $extension = strtolower($file->ext ?? '');

            return in_array($dataSourceCode, self::CSV_DATA_SOURCES, true)
                && $extension === 'csv';
        });

        return $csvFiles->isNotEmpty();
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
     * Transforma un CSV según el tipo de data source.
     *
     * - BASCAR: Extrae columnas específicas + resto en JSON
     * - BAPRPO, DATPOL: Todo en JSON
     *
     * @param string $originalCsvPath Ruta del CSV original
     * @param int $runId Run ID
     * @param string $dataSourceCode Código del data source
     *
     * @return string Ruta del CSV transformado
     *
     * @throws RuntimeException
     */
    private function transformCsvToJsonFormat(
        string $originalCsvPath,
        int $runId,
        string $dataSourceCode
    ): string {
        $transformedCsvPath = $originalCsvPath . '.transformed.csv';

        $input = fopen($originalCsvPath, 'r');
        if ($input === false) {
            throw new RuntimeException('No se pudo abrir CSV: ' . $originalCsvPath);
        }

        $output = fopen($transformedCsvPath, 'w');
        if ($output === false) {
            fclose($input);
            throw new RuntimeException('No se pudo crear CSV transformado: ' . $transformedCsvPath);
        }

        // Leer header del CSV original
        $headerLine = fgets($input);
        if ($headerLine === false) {
            fclose($input);
            fclose($output);
            throw new RuntimeException('CSV sin header: ' . $originalCsvPath);
        }
        $headers = str_getcsv(trim($headerLine), ';', '"', '');

        // Crear índice de columnas
        $columnIndex = array_flip($headers);

        // Determinar si hay columnas específicas a extraer
        $hasSpecificColumns = isset(self::CSV_COLUMN_EXTRACTION[$dataSourceCode]);
        $extraction = self::CSV_COLUMN_EXTRACTION[$dataSourceCode] ?? [];

        // Escribir header del CSV transformado (sin usar fputcsv para evitar backslashes)
        if ($hasSpecificColumns) {
            // BASCAR: run_id + columnas específicas + periodo + composite_key + data + cantidad_trabajadores + observacion_trabajadores + sheet_name
            $headerLine = implode(';', array_values(self::COLUMN_MAP[$dataSourceCode]));
            fwrite($output, $headerLine . "\n");
        } else {
            // BAPRPO, DATPOL: run_id + data + sheet_name
            fwrite($output, "run_id;data;sheet_name\n");
        }

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

            if ($hasSpecificColumns) {
                // BASCAR: Extraer columnas específicas + todo en JSON
                $extractedValues = [];
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

                // Sanitizar valor numérico: eliminar separadores de miles y espacios
                $valorTotal = $row[$columnIndex['VALOR_TOTAL_FACT']] ?? null;
                if ($valorTotal !== null) {
                    $valorTotal = trim(str_replace('.', '', $valorTotal));
                    // Si queda vacío después de limpiar, dejarlo como null
                    if ($valorTotal === '') {
                        $valorTotal = null;
                    }
                }

                // Construir fila: run_id + columnas específicas + periodo + composite_key + data + trabajadores + sheet_name
                $outputRow = [
                    $runId,
                    str_replace('\\', ' ', $row[$columnIndex['NUM_TOMADOR']] ?? ''),
                    str_replace('\\', ' ', $row[$columnIndex['FECHA_INICIO_VIG']] ?? ''),
                    $valorTotal, // Valor sanitizado
                    null, // periodo (se calcula después)
                    null, // composite_key (se genera después)
                    json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    null, // cantidad_trabajadores (se calcula después)
                    null, // observacion_trabajadores (se calcula después)
                    '', // sheet_name vacío para CSVs
                ];
            } else {
                // BAPRPO, DATPOL: Todo en JSON
                $jsonData = [];
                foreach ($headers as $index => $header) {
                    $value = $row[$index] ?? null;

                    // Sanitizar backslashes
                    if ($value !== null && is_string($value)) {
                        $originalValue = $value;
                        $value = str_replace('\\', ' ', $value);

                        if ($originalValue !== $value) {
                            $hadWarning = true;
                        }
                    }

                    $jsonData[$header] = $value;
                }

                $outputRow = [
                    $runId,
                    json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    '', // sheet_name vacío para CSVs
                ];
            }

            if ($hadWarning) {
                $rowsWithWarnings[] = $currentLine;
            }

            // Escribir CSV manualmente para evitar que fputcsv() agregue backslashes
            // Formato: valor1;valor2;"valor3" (quotes solo cuando necesario)
            $csvLine = implode(';', array_map(function($value, $index) use ($hasSpecificColumns) {
                if ($value === null || $value === '') {
                    return '';
                }
                // Convertir a string si es necesario
                $stringValue = (string) $value;

                // SIEMPRE poner el campo JSON entre quotes (es el campo 'data')
                $isJsonField = $hasSpecificColumns ? ($index === 6) : ($index === 1);

                if ($isJsonField) {
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
            'data_source' => $dataSourceCode,
            'original' => basename($originalCsvPath),
            'transformed' => basename($transformedCsvPath),
            'rows_processed' => $rowsProcessed,
        ];

        if (!empty($rowsWithWarnings)) {
            $logData['rows_with_backslash_warnings'] = count($rowsWithWarnings);
            $logData['sample_warning_lines'] = array_slice($rowsWithWarnings, 0, 10); // Primeras 10

            Log::warning('⚠️  CSV transformado con advertencias: backslashes reemplazados', $logData);
        } else {
            Log::info('✅ CSV transformado sin advertencias', $logData);
        }

        return $transformedCsvPath;
    }
}
