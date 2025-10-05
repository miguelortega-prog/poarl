<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CollectionNoticeRun;
use App\Services\Recaudo\ResilientCsvImporter;
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
 * Job RESILIENTE para cargar archivos CSV (BASCAR, BAPRPO, DATPOL) línea por línea.
 *
 * A diferencia del enfoque con PostgreSQL COPY (todo o nada), este job:
 * - Procesa línea por línea en chunks de 1000 registros
 * - Registra errores sin frenar el proceso
 * - Guarda bitácora de líneas fallidas en csv_import_error_logs
 * - Es resiliente ante caracteres escapados o separadores mal manejados
 *
 * Performance esperada para 800k registros:
 * - Tiempo: ~2-5 minutos (vs ~3s con COPY, pero más resiliente)
 * - Sin fallos catastróficos por errores en líneas individuales
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
     * Solo 1 intento para evitar duplicación de datos.
     */
    public int $tries = 1;

    /**
     * Tiempo máximo de ejecución (4 horas para procesar múltiples CSVs grandes con seguridad).
     */
    public int $timeout = 14400;

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
        $this->onQueue('default');
    }

    /**
     * Ejecuta el job de carga de CSV de forma resiliente línea por línea.
     */
    public function handle(
        FilesystemFactory $filesystem,
        ResilientCsvImporter $importer
    ): void {
        // Verificar si el batch fue cancelado
        if ($this->batch()?->cancelled()) {
            return;
        }

        Log::info('🚀 Iniciando carga RESILIENTE de archivos CSV línea por línea', [
            'job' => self::class,
            'run_id' => $this->runId,
            'method' => 'Resilient Line-by-Line with Chunks',
        ]);

        try {
            $run = CollectionNoticeRun::with(['files.dataSource'])->findOrFail($this->runId);

            // IDEMPOTENCIA: Limpiar tablas antes de insertar para evitar duplicados
            // Esto garantiza que si el job se reintenta, no haya datos duplicados
            Log::info('Limpiando tablas CSV para garantizar idempotencia', [
                'run_id' => $this->runId,
            ]);

            foreach (self::TABLE_MAP as $tableName) {
                $deleted = \DB::table($tableName)->where('run_id', $this->runId)->delete();
                if ($deleted > 0) {
                    Log::warning('Registros previos eliminados (idempotencia)', [
                        'table' => $tableName,
                        'run_id' => $this->runId,
                        'deleted_rows' => $deleted,
                    ]);
                }
            }

            $disk = $filesystem->disk('collection');
            $totalRowsLoaded = 0;
            $filesLoaded = [];

            foreach ($run->files as $file) {
                $dataSourceCode = $file->dataSource->code ?? 'unknown';
                $extension = strtolower($file->ext ?? '');

                // Solo procesar archivos CSV de los data sources esperados
                if (!array_key_exists($dataSourceCode, self::TABLE_MAP)) {
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

                Log::info('📥 Cargando CSV de forma resiliente', [
                    'run_id' => $run->id,
                    'data_source' => $dataSourceCode,
                    'table' => $tableName,
                    'file_path' => $file->path,
                    'size_mb' => round($fileSize / 1024 / 1024, 2),
                ]);

                // NOTA: Sanitización deshabilitada para permitir importación de todas las columnas
                // El ResilientCsvImporter maneja todos los casos de error sin necesidad de sanitización previa

                // Obtener columnas de la tabla (excluir id, run_id y created_at)
                $columns = \DB::select(
                    "SELECT column_name
                     FROM information_schema.columns
                     WHERE table_name = ?
                     AND column_name NOT IN ('id', 'run_id', 'created_at')
                     ORDER BY ordinal_position",
                    [$tableName]
                );
                $columns = array_column($columns, 'column_name');

                // Usar ResilientCsvImporter (procesa línea por línea con chunks)
                $result = $importer->importFromFile(
                    $tableName,
                    $csvPath,
                    $columns,
                    (int) $run->id,
                    $dataSourceCode,
                    ';',
                    true // hasHeader
                );

                $totalRowsLoaded += $result['success_rows'];

                Log::info('✅ CSV cargado de forma resiliente', [
                    'run_id' => $run->id,
                    'data_source' => $dataSourceCode,
                    'total_rows' => $result['total_rows'],
                    'success_rows' => $result['success_rows'],
                    'error_rows' => $result['error_rows'],
                    'errors_logged' => $result['errors_logged'],
                    'duration_ms' => $result['duration_ms'],
                    'success_rate' => $result['total_rows'] > 0
                        ? round(($result['success_rows'] / $result['total_rows']) * 100, 2)
                        : 100,
                ]);

                $filesLoaded[] = [
                    'data_source' => $dataSourceCode,
                    'total_rows' => $result['total_rows'],
                    'success_rows' => $result['success_rows'],
                    'error_rows' => $result['error_rows'],
                    'duration_ms' => $result['duration_ms'],
                ];
            }

            Log::info('🎉 Carga RESILIENTE de CSV completada', [
                'job' => self::class,
                'run_id' => $run->id,
                'files_loaded' => count($filesLoaded),
                'total_success_rows' => $totalRowsLoaded,
                'files_summary' => $filesLoaded,
                'method' => 'Resilient Line-by-Line with Chunks',
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
