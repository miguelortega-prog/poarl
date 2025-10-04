<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\DTOs\Recaudo\Comunicados\ProcessingContextDto;
use App\DTOs\Recaudo\SanitizedCsvResultDto;
use App\Services\Recaudo\CsvSanitizerService;
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
     * Mapeo de códigos de data source a tablas PostgreSQL.
     */
    private const TABLE_MAP = [
        'BASCAR' => 'data_source_bascar',
        'BAPRPO' => 'data_source_baprpo',
        'DATPOL' => 'data_source_datpol',
    ];

    public function __construct(
        private FilesystemFactory $filesystem,
        private PostgreSQLCopyImporter $copyImporter,
        private CsvSanitizerService $csvSanitizer
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
            if (!array_key_exists($dataSourceCode, self::TABLE_MAP)) {
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
            $sanitizedResult = null;
            if ($this->csvSanitizer->supports($dataSourceCode)) {
                Log::info('🔄 Transformando CSV (columnas→JSON) antes de COPY', [
                    'run_id' => $run->id,
                    'data_source' => $dataSourceCode,
                ]);

                $sanitizedResult = $this->csvSanitizer->sanitize(
                    $csvPath,
                    $run->id,
                    $dataSourceCode
                );

                $finalCsvPath = $sanitizedResult->path;
            }

            // Obtener columnas de la tabla
            $columns = $this->getTableColumns($tableName, $dataSourceCode);

            try {
                // Importar con PostgreSQL COPY
                $result = $this->copyImporter->importFromFile(
                    $tableName,
                    $finalCsvPath,
                    $columns,
                    ';', // Delimitador
                    true // Tiene header
                );
            } finally {
                $this->cleanupTemporaryCsv($sanitizedResult);
            }

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

            return array_key_exists($dataSourceCode, self::TABLE_MAP)
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
        if ($this->csvSanitizer->supports($dataSourceCode)) {
            return $this->csvSanitizer->getColumnMap($dataSourceCode);
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

    private function cleanupTemporaryCsv(?SanitizedCsvResultDto $sanitizedResult): void
    {
        if ($sanitizedResult === null) {
            return;
        }

        if ($sanitizedResult->temporary && file_exists($sanitizedResult->path)) {
            @unlink($sanitizedResult->path);
        }
    }
}
