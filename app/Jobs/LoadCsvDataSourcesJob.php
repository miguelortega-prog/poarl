<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CollectionNoticeRun;
use App\Services\Recaudo\PostgreSQLCopyImporter;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Job OPTIMIZADO para cargar archivos CSV (BASCAR, BAPRPO, DATPOL) usando PostgreSQL COPY.
 *
 * Este job se ejecuta en paralelo con otros jobs de carga.
 * Usa COPY FROM STDIN (10-50x más rápido que chunks).
 *
 * Performance esperada:
 * - CSV 100MB: ~3s (vs ~30s con chunks de 5000)
 */
final class LoadCsvDataSourcesJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Número de intentos del job.
     */
    public int $tries = 2;

    /**
     * Tiempo máximo de ejecución (5 minutos).
     */
    public int $timeout = 300;

    /**
     * Códigos de data sources CSV a cargar.
     */
    private const CSV_DATA_SOURCES = ['BASCAR', 'BAPRPO', 'DATPOL'];

    /**
     * Map de códigos a tablas PostgreSQL.
     */
    private const TABLE_MAP = [
        'BASCAR' => 'data_source_bascar',
        'BAPRPO' => 'data_source_baprpo',
        'DATPOL' => 'data_source_datpol',
    ];

    /**
     * @param int $runId ID del run a procesar
     */
    public function __construct(
        private readonly int $runId
    ) {
        $this->onQueue('csv-loading');
    }

    /**
     * Ejecuta el job de carga de CSV con PostgreSQL COPY.
     */
    public function handle(
        FilesystemFactory $filesystem,
        PostgreSQLCopyImporter $importer
    ): void {
        // Verificar si el batch fue cancelado
        if ($this->batch()?->cancelled()) {
            return;
        }

        Log::info('🚀 Iniciando carga OPTIMIZADA de archivos CSV con PostgreSQL COPY', [
            'job' => self::class,
            'run_id' => $this->runId,
            'method' => 'PostgreSQL COPY FROM STDIN',
        ]);

        try {
            $run = CollectionNoticeRun::with(['files.dataSource'])->findOrFail($this->runId);

            $disk = $filesystem->disk('collection');
            $totalRowsLoaded = 0;
            $filesLoaded = [];

            foreach ($run->files as $file) {
                $dataSourceCode = $file->dataSource->code ?? 'unknown';
                $extension = strtolower($file->ext ?? '');

                // Solo procesar archivos CSV de los data sources esperados
                if (!in_array($dataSourceCode, self::CSV_DATA_SOURCES, true)) {
                    continue;
                }

                if ($extension !== 'csv') {
                    continue;
                }

                if (!$disk->exists($file->path)) {
                    throw new RuntimeException(
                        sprintf('Archivo CSV no encontrado: %s', $file->path)
                    );
                }

                $tableName = self::TABLE_MAP[$dataSourceCode] ?? null;
                if ($tableName === null) {
                    throw new RuntimeException("Data source no soportado: {$dataSourceCode}");
                }

                $csvPath = $disk->path($file->path);
                $fileSize = $disk->size($file->path);

                Log::info('📥 Cargando CSV con PostgreSQL COPY', [
                    'run_id' => $run->id,
                    'data_source' => $dataSourceCode,
                    'table' => $tableName,
                    'file_path' => $file->path,
                    'size_mb' => round($fileSize / 1024 / 1024, 2),
                ]);

                // Obtener columnas de la tabla (excluir id y created_at)
                $columns = \DB::select(
                    "SELECT column_name
                     FROM information_schema.columns
                     WHERE table_name = ?
                     AND column_name NOT IN ('id', 'created_at')
                     ORDER BY ordinal_position",
                    [$tableName]
                );
                $columns = array_column($columns, 'column_name');

                // Usar PostgreSQL COPY FROM STDIN (10-50x más rápido)
                $result = $importer->importFromFile(
                    $tableName,
                    $csvPath,
                    $columns,
                    ';',
                    true // hasHeader
                );

                $totalRowsLoaded += $result['rows'];

                Log::info('✅ CSV cargado con COPY exitosamente', [
                    'run_id' => $run->id,
                    'data_source' => $dataSourceCode,
                    'rows_imported' => $result['rows'],
                    'duration_ms' => $result['duration_ms'],
                    'rows_per_second' => $result['duration_ms'] > 0
                        ? round($result['rows'] / ($result['duration_ms'] / 1000))
                        : 0,
                ]);

                $filesLoaded[] = [
                    'data_source' => $dataSourceCode,
                    'rows_count' => $result['rows'],
                    'duration_ms' => $result['duration_ms'],
                ];
            }

            Log::info('🎉 Carga OPTIMIZADA de CSV completada (PostgreSQL COPY)', [
                'job' => self::class,
                'run_id' => $run->id,
                'files_loaded' => count($filesLoaded),
                'total_rows' => $totalRowsLoaded,
                'method' => 'COPY FROM STDIN',
            ]);
        } catch (Throwable $exception) {
            Log::error('Error en carga de archivos CSV', [
                'job' => self::class,
                'run_id' => $this->runId,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }
    }

    /**
     * Maneja el fallo del job.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Job de carga CSV falló definitivamente', [
            'job' => self::class,
            'run_id' => $this->runId,
            'error' => $exception->getMessage(),
        ]);
    }
}
