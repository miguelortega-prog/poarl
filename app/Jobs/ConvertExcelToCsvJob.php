<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CollectionNoticeRunFile;
use App\Services\Recaudo\ExcelToCsvConverter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job para convertir archivos Excel a CSV en background.
 *
 * Se ejecuta después de que un archivo Excel es subido completamente,
 * convierte el Excel a CSV y actualiza el registro en base de datos.
 */
class ConvertExcelToCsvJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Tiempo máximo de ejecución: 15 minutos.
     */
    public int $timeout = 900;

    /**
     * Número de intentos.
     */
    public int $tries = 2;

    /**
     * @param int $fileId ID del CollectionNoticeRunFile
     * @param string|null $sheetName Nombre de la hoja a convertir (null = auto-detectar basado en periodo del run)
     * @param bool $allSheets Si true, convierte todas las hojas a CSVs separados
     */
    public function __construct(
        private readonly int $fileId,
        private readonly ?string $sheetName = null,
        private readonly bool $allSheets = false
    ) {
        $this->onQueue('collection-notices');
    }

    /**
     * Execute the job.
     */
    public function handle(
        ExcelToCsvConverter $converter,
        FilesystemFactory $filesystem
    ): void {
        $file = CollectionNoticeRunFile::with(['run', 'dataSource'])->find($this->fileId);

        if ($file === null) {
            Log::warning('Archivo no encontrado para conversión Excel->CSV', [
                'file_id' => $this->fileId,
            ]);

            return;
        }

        $run = $file->run;
        $period = $run?->period;
        $dataSourceCode = $file->dataSource?->code;

        // Determinar qué hoja(s) convertir
        $sheetName = $this->sheetName;
        $allSheets = $this->allSheets;

        // DETTRA y PAGPLA son históricos completos: convertir primera hoja completa
        if (in_array($dataSourceCode, ['DETTRA', 'PAGPLA'], true)) {
            $sheetName = null; // Primera hoja completa

            Log::info('Data source histórico detectado, convirtiendo primera hoja completa', [
                'file_id' => $this->fileId,
                'run_id' => $file->collection_notice_run_id,
                'data_source' => $dataSourceCode,
            ]);
        }
        // PAGAPL: filtrar por periodo del run
        elseif ($dataSourceCode === 'PAGAPL') {
            // Si no se especificó sheetName, usar el periodo del run
            if ($sheetName === null && !$allSheets && $period !== null) {
                $sheetName = $period;

                Log::info('Auto-detectando hoja de PAGAPL basado en periodo del run', [
                    'file_id' => $this->fileId,
                    'run_id' => $file->collection_notice_run_id,
                    'period' => $period,
                    'sheet_name' => $sheetName,
                ]);
            }

            // Si el periodo es null o "todos", convertir todas las hojas
            if ($period === null || $period === 'ALL' || $period === 'TODOS') {
                $allSheets = true;

                Log::info('PAGAPL con periodo "todos", convirtiendo todas las hojas', [
                    'file_id' => $this->fileId,
                    'run_id' => $file->collection_notice_run_id,
                ]);
            }
        }
        // Otros archivos Excel: primera hoja
        else {
            $sheetName = null; // Primera hoja

            Log::info('Archivo Excel genérico, convirtiendo primera hoja', [
                'file_id' => $this->fileId,
                'run_id' => $file->collection_notice_run_id,
                'data_source' => $dataSourceCode,
            ]);
        }

        Log::info('Iniciando conversión Excel a CSV (Job)', [
            'file_id' => $this->fileId,
            'run_id' => $file->collection_notice_run_id,
            'original_path' => $file->path,
            'size_mb' => round($file->size / 1024 / 1024, 2),
            'sheet_name' => $sheetName,
            'all_sheets' => $allSheets,
        ]);

        $disk = $filesystem->disk($file->disk);

        try {
            if ($allSheets) {
                // TODO: Implementar conversión de todas las hojas
                // Por ahora, usar la primera hoja
                Log::warning('Conversión de todas las hojas aún no implementada, usando primera hoja', [
                    'file_id' => $this->fileId,
                ]);

                $result = $converter->convertAndReplace(
                    $disk,
                    $file->path,
                    null // Primera hoja
                );
            } else {
                // Convertir solo la hoja especificada
                $result = $converter->convertAndReplace(
                    $disk,
                    $file->path,
                    $sheetName
                );
            }

            // Actualizar registro en BD
            $file->update([
                'path' => $result['csv_path'],
                'ext' => 'csv',
                'size' => $result['size'],
                'metadata' => array_merge($file->metadata ?? [], [
                    'converted_from_excel' => true,
                    'excel_rows' => $result['rows'],
                    'sheet_name' => $sheetName,
                    'all_sheets' => $allSheets,
                    'converted_at' => now()->toIso8601String(),
                ]),
            ]);

            Log::info('Conversión Excel a CSV completada (Job)', [
                'file_id' => $this->fileId,
                'run_id' => $file->collection_notice_run_id,
                'csv_path' => $result['csv_path'],
                'rows' => $result['rows'],
                'size_mb' => round($result['size'] / 1024 / 1024, 2),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al convertir Excel a CSV (Job)', [
                'file_id' => $this->fileId,
                'run_id' => $file->collection_notice_run_id,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Job de conversión Excel->CSV falló definitivamente', [
            'file_id' => $this->fileId,
            'sheet_name' => $this->sheetName,
            'error' => $exception->getMessage(),
        ]);
    }
}
