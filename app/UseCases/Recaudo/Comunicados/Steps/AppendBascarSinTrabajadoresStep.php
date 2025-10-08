<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use App\Models\CollectionNoticeRunResultFile;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Agregar registros de BASCAR sin trabajadores al detalle de trabajadores.
 *
 * Filtra registros de BASCAR donde observacion_trabajadores = 'Sin trabajadores activos'
 * y los agrega al archivo detalle_trabajadores{run_id}.csv con valores por defecto.
 *
 * Estos son empleadores que NO tienen trabajadores activos en DETTRA.
 *
 * Valores especiales:
 * - CLS_RICT: 'I' (número romano 1)
 * - FCH_INVI: '01010001' (valor fijo)
 * - TPO_COT: '0' (valor fijo)
 * - TRAB_EXPUESTOS: 1 (valor fijo)
 */
final class AppendBascarSinTrabajadoresStep implements ProcessingStepInterface
{
    public function __construct(
        private readonly FilesystemFactory $filesystem
    ) {
    }

    public function getName(): string
    {
        return 'Agregar BASCAR sin trabajadores a detalle';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        $startTime = microtime(true);

        Log::info('📋 Agregando registros de BASCAR sin trabajadores activos', [
            'step' => self::class,
            'run_id' => $run->id,
        ]);

        // Contar registros sin trabajadores activos
        $totalRecords = $this->countBascarWithoutWorkers($run);

        if ($totalRecords === 0) {
            Log::info('No hay registros de BASCAR sin trabajadores activos', [
                'run_id' => $run->id,
            ]);
            return;
        }

        Log::info('Registros de BASCAR sin trabajadores encontrados', [
            'run_id' => $run->id,
            'total' => $totalRecords,
        ]);

        // Agregar registros al archivo existente
        $this->appendToWorkerDetailFile($run, $totalRecords);

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('✅ Registros sin trabajadores agregados al detalle', [
            'run_id' => $run->id,
            'records_added' => $totalRecords,
            'duration_ms' => $duration,
        ]);
    }

    /**
     * Cuenta registros de BASCAR sin trabajadores activos.
     */
    private function countBascarWithoutWorkers(CollectionNoticeRun $run): int
    {
        return (int) DB::selectOne("
            SELECT COUNT(*) as count
            FROM data_source_bascar
            WHERE run_id = ?
                AND observacion_trabajadores = 'Sin trabajadores activos'
        ", [$run->id])->count;
    }

    /**
     * Agrega registros al archivo de detalle de trabajadores.
     */
    private function appendToWorkerDetailFile(CollectionNoticeRun $run, int $totalRecords): void
    {
        $fileName = sprintf('detalle_trabajadores%d.csv', $run->id);
        $relativeDir = sprintf('collection_notice_runs/%d/results', $run->id);
        $relativePath = $relativeDir . '/' . $fileName;

        $disk = $this->filesystem->disk('collection');

        // Verificar si el archivo existe
        if (!$disk->exists($relativePath)) {
            Log::warning('Archivo de detalle de trabajadores no existe, se creará nuevo', [
                'run_id' => $run->id,
                'file_path' => $relativePath,
            ]);

            // Crear directorio si no existe
            if (!$disk->exists($relativeDir)) {
                $disk->makeDirectory($relativeDir);
            }

            // Crear archivo con encabezado
            $disk->put($relativePath, "TPO_IDEN_TRABAJADOR;NRO_IDEN;AÑO;MES;TPO_EMP;NRO_IDVI;CLS_RICT;FCH_INVI;PÓLIZA;VALOR;TPO_COT;FCH_FIN;TRAB_EXPUESTOS\n");
        }

        $existingContent = $disk->get($relativePath);

        Log::info('Agregando registros sin trabajadores al archivo', [
            'run_id' => $run->id,
            'total_records' => $totalRecords,
            'file' => $fileName,
        ]);

        // Extraer año y mes del período (formato YYYYMM)
        $period = $run->period;
        $year = substr($period, 0, 4);
        $month = substr($period, 4, 2);

        // Generar contenido nuevo
        $newContent = '';

        // Procesar en chunks
        $chunkSize = 5000;
        $offset = 0;
        $processedRows = 0;

        while ($offset < $totalRecords) {
            $rows = DB::select("
                SELECT
                    IDENT_ASEGURADO,
                    NUM_TOMADOR,
                    NUM_POLIZA,
                    VALOR_TOTAL_FACT
                FROM data_source_bascar
                WHERE run_id = ?
                    AND observacion_trabajadores = 'Sin trabajadores activos'
                ORDER BY id
                LIMIT ?
                OFFSET ?
            ", [$run->id, $chunkSize, $offset]);

            foreach ($rows as $row) {
                // TODO: NRO_IDVI pendiente de definir - actualmente se deja vacío
                $nroIdvi = '';

                // TODO: NUM_POLIZA está quedando en notación científica en el job de almacenamiento
                // Esto debe corregirse en LoadCsvDataSourcesJob o LoadExcelWithCopyJob
                $poliza = $row->NUM_POLIZA ?? '';

                $newContent .= sprintf(
                    "%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s\n",
                    $row->IDENT_ASEGURADO ?? '',    // TPO_IDEN_TRABAJADOR
                    $row->NUM_TOMADOR ?? '',         // NRO_IDEN
                    $year,                           // AÑO
                    $month,                          // MES
                    $row->IDENT_ASEGURADO ?? '',    // TPO_EMP
                    $nroIdvi,                        // NRO_IDVI (TODO: pendiente definir)
                    'I',                             // CLS_RICT (número romano 1)
                    '01010001',                      // FCH_INVI (valor fijo)
                    $poliza,                         // PÓLIZA (TODO: notación científica)
                    $row->VALOR_TOTAL_FACT ?? 0,    // VALOR
                    '0',                             // TPO_COT (valor fijo)
                    'NO REGISTRA',                   // FCH_FIN (valor fijo)
                    1                                // TRAB_EXPUESTOS (valor fijo)
                );
                $processedRows++;
            }

            $offset += $chunkSize;
        }

        // Guardar archivo (append)
        $finalContent = $existingContent . $newContent;
        $disk->put($relativePath, $finalContent);
        $fileSize = $disk->size($relativePath);

        // Actualizar registro en base de datos
        $existingFile = CollectionNoticeRunResultFile::where('collection_notice_run_id', $run->id)
            ->where('file_type', 'detalle_trabajadores')
            ->first();

        if ($existingFile) {
            // Actualizar registro existente
            $existingFile->update([
                'size' => $fileSize,
                'records_count' => ($existingFile->records_count ?? 0) + $processedRows,
                'metadata' => array_merge($existingFile->metadata ?? [], [
                    'updated_at' => now()->toIso8601String(),
                    'bascar_sin_trabajadores_added' => $processedRows,
                ]),
            ]);

            Log::info('✅ Archivo de detalle actualizado', [
                'run_id' => $run->id,
                'file_path' => $relativePath,
                'new_records' => $processedRows,
                'total_records' => $existingFile->records_count,
                'size_kb' => round($fileSize / 1024, 2),
            ]);
        } else {
            // Crear nuevo registro
            CollectionNoticeRunResultFile::create([
                'collection_notice_run_id' => $run->id,
                'file_type' => 'detalle_trabajadores',
                'file_name' => $fileName,
                'disk' => 'collection',
                'path' => $relativePath,
                'size' => $fileSize,
                'records_count' => $processedRows,
                'metadata' => [
                    'generated_at' => now()->toIso8601String(),
                    'step' => 'append_bascar_sin_trabajadores',
                    'period' => $run->period,
                ],
            ]);

            Log::info('✅ Archivo de detalle creado', [
                'run_id' => $run->id,
                'file_path' => $relativePath,
                'records_count' => $processedRows,
                'size_kb' => round($fileSize / 1024, 2),
            ]);
        }
    }
}
