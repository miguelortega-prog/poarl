<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CollectionNoticeRunFile;
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
 * Job RESILIENTE para cargar UN archivo CSV línea por línea.
 *
 * A diferencia del enfoque con PostgreSQL COPY (todo o nada), este job:
 * - Procesa línea por línea en chunks de 10000 registros
 * - Registra errores sin frenar el proceso
 * - Guarda bitácora de líneas fallidas en csv_import_error_logs
 * - Es resiliente ante caracteres escapados o separadores mal manejados
 * - Convierte automáticamente de Latin1 a UTF-8 si es necesario
 *
 * Patrón de uso:
 * - Se instancia UN job por cada archivo CSV a procesar
 * - Consistente con LoadExcelWithCopyJob (mismo patrón de diseño)
 * - Reutilizable para diferentes tipos de comunicados
 *
 * Performance esperada:
 * - BASCAR (255k registros): ~26 minutos
 * - BAPRPO (50k registros): ~2-3 minutos
 * - DATPOL (68k registros): ~5-7 minutos
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
     * Tiempo máximo de ejecución (4 horas para archivos CSV grandes con seguridad).
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
     * @param int $fileId ID del archivo CSV a procesar
     * @param string $dataSourceCode Código del data source (BASCAR, BAPRPO, DATPOL)
     */
    public function __construct(
        private readonly int $fileId,
        private readonly string $dataSourceCode
    ) {
        $this->onQueue('default');
    }

    /**
     * Ejecuta el job de carga de UN archivo CSV de forma resiliente línea por línea.
     */
    public function handle(
        FilesystemFactory $filesystem,
        ResilientCsvImporter $importer
    ): void {
        // Verificar si el batch fue cancelado
        if ($this->batch()?->cancelled()) {
            return;
        }

        // Cargar el archivo específico
        $file = CollectionNoticeRunFile::with(['run', 'dataSource'])->find($this->fileId);

        if ($file === null) {
            Log::warning('Archivo CSV no encontrado', [
                'file_id' => $this->fileId,
                'data_source' => $this->dataSourceCode,
            ]);
            return;
        }

        $runId = $file->collection_notice_run_id;
        $tableName = self::TABLE_MAP[$this->dataSourceCode] ?? null;

        if ($tableName === null) {
            throw new RuntimeException("Data source no soportado: {$this->dataSourceCode}");
        }

        Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        Log::info('🚀 INICIANDO IMPORTACIÓN CSV RESILIENTE');
        Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        Log::info('📊 Data Source: ' . $this->dataSourceCode);
        Log::info('📁 Archivo: ' . basename($file->path));
        Log::info('💾 Tamaño: ' . round($file->size / 1024 / 1024, 2) . ' MB');
        Log::info('🎯 Tabla destino: ' . $tableName);
        Log::info('⚙️  Método: Resilient Line-by-Line (UTF-8 conversion)');
        Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        try {
            // IDEMPOTENCIA: Limpiar tabla antes de insertar para evitar duplicados
            Log::info('Limpiando tabla CSV para garantizar idempotencia', [
                'table' => $tableName,
                'run_id' => $runId,
            ]);

            $deleted = \DB::table($tableName)->where('run_id', $runId)->delete();
            if ($deleted > 0) {
                Log::warning('Registros previos eliminados (idempotencia)', [
                    'table' => $tableName,
                    'run_id' => $runId,
                    'deleted_rows' => $deleted,
                ]);
            }

            $disk = $filesystem->disk('collection');

            if (!$disk->exists($file->path)) {
                throw new RuntimeException(
                    sprintf('Archivo CSV no encontrado: %s', $file->path)
                );
            }

            $csvPath = $disk->path($file->path);
            $fileSize = $disk->size($file->path);

            Log::info('📥 Cargando CSV de forma resiliente', [
                'run_id' => $runId,
                'data_source' => $this->dataSourceCode,
                'table' => $tableName,
                'file_path' => $file->path,
                'size_mb' => round($fileSize / 1024 / 1024, 2),
            ]);

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
                (int) $runId,
                $this->dataSourceCode,
                ';',
                true // hasHeader
            );

            Log::info('');
            Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            Log::info('🎉 IMPORTACIÓN CSV RESILIENTE COMPLETADA');
            Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            Log::info('📊 Data Source: ' . $this->dataSourceCode);
            Log::info('📈 Total de filas: ' . number_format($result['total_rows']));
            Log::info('✅ Filas exitosas: ' . number_format($result['success_rows']));
            Log::info('❌ Filas con error: ' . number_format($result['error_rows']));
            Log::info('📋 Errores registrados: ' . number_format($result['errors_logged']));
            Log::info('⏱️  Duración: ' . round($result['duration_ms'] / 1000, 2) . 's');
            Log::info('📊 Tasa de éxito: ' . ($result['total_rows'] > 0
                ? round(($result['success_rows'] / $result['total_rows']) * 100, 2)
                : 100) . '%');
            Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            Log::info('');

        } catch (Throwable $exception) {
            Log::error('Error en carga resiliente de archivo CSV', [
                'job' => self::class,
                'file_id' => $this->fileId,
                'run_id' => $runId,
                'data_source' => $this->dataSourceCode,
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
            'file_id' => $this->fileId,
            'data_source' => $this->dataSourceCode,
            'error' => $exception->getMessage(),
        ]);
    }
}
