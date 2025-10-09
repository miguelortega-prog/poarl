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
 * Step: Excluir registros sin datos de contacto.
 *
 * Identifica aportantes que no tienen tipo_de_envio (sin email ni dirección)
 * y los excluye del proceso:
 *
 * Criterios de exclusión:
 * - tipo_de_envio IS NULL (sin email ni dirección válida)
 *
 * Acciones:
 * 1. Identifica registros que cumplen los criterios
 * 2. Los agrega al archivo de excluidos (excluidos{run_id}.csv)
 * 3. Elimina estos registros de data_source_bascar
 *
 * Motivo de exclusión: "Sin datos de contacto"
 */
final class ExcludeSinDatosContactoStep implements ProcessingStepInterface
{
    public function __construct(
        private readonly FilesystemFactory $filesystem
    ) {
    }

    public function getName(): string
    {
        return 'Excluir registros sin datos de contacto';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        $startTime = microtime(true);

        Log::info('🚫 Excluyendo registros sin datos de contacto', [
            'step' => self::class,
            'run_id' => $run->id,
        ]);

        // Contar registros que cumplen criterio de exclusión
        $toExcludeCount = $this->countSinDatosContacto($run);

        if ($toExcludeCount === 0) {
            Log::info('No hay registros sin datos de contacto para excluir', [
                'run_id' => $run->id,
            ]);
            return;
        }

        Log::info('Registros a excluir (sin datos de contacto)', [
            'run_id' => $run->id,
            'count' => $toExcludeCount,
        ]);

        // Agregar registros al archivo de excluidos
        $this->appendToExcludedFile($run, $toExcludeCount);

        // Eliminar registros de BASCAR
        $deleted = $this->deleteSinDatosContactoFromBascar($run);

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('✅ Exclusión de registros sin datos de contacto completada', [
            'run_id' => $run->id,
            'excluded_count' => $toExcludeCount,
            'deleted_count' => $deleted,
            'duration_ms' => $duration,
        ]);
    }

    /**
     * Cuenta registros sin datos de contacto (tipo_de_envio IS NULL).
     */
    private function countSinDatosContacto(CollectionNoticeRun $run): int
    {
        return (int) DB::selectOne("
            SELECT COUNT(*) as count
            FROM data_source_bascar
            WHERE run_id = ?
                AND tipo_de_envio IS NULL
        ", [$run->id])->count;
    }

    /**
     * Agrega registros al archivo de excluidos.
     */
    private function appendToExcludedFile(CollectionNoticeRun $run, int $totalRecords): void
    {
        $fileName = sprintf('excluidos%d.csv', $run->id);
        $relativeDir = sprintf('collection_notice_runs/%d/results', $run->id);
        $relativePath = $relativeDir . '/' . $fileName;

        $disk = $this->filesystem->disk('collection');

        // Verificar si el archivo existe
        $fileExists = $disk->exists($relativePath);
        $existingContent = $fileExists ? $disk->get($relativePath) : '';

        Log::info('Agregando registros sin datos de contacto a archivo de excluidos', [
            'run_id' => $run->id,
            'total_records' => $totalRecords,
            'file' => $fileName,
            'file_exists' => $fileExists,
        ]);

        // Obtener tipo de comunicado
        $tipoComunicado = $run->type?->name ?? 'Sin tipo';

        // Generar CSV content para los nuevos registros
        $newContent = '';

        // Si el archivo no existe, agregar encabezado
        if (!$fileExists) {
            // Crear directorio si no existe
            if (!$disk->exists($relativeDir)) {
                $disk->makeDirectory($relativeDir);
            }
            $newContent .= "FECHA_CRUCE;NUMERO_ID_APORTANTE;PERIODO;TIPO_COMUNICADO;VALOR;MOTIVO_EXCLUSION\n";
        }

        // Procesar en chunks
        $chunkSize = 5000;
        $offset = 0;
        $processedRows = 0;

        while ($offset < $totalRecords) {
            $rows = DB::select("
                SELECT
                    TO_CHAR(NOW(), 'DD/MM/YYYY') as fecha_cruce,
                    num_tomador as numero_id_aportante,
                    periodo,
                    ? as tipo_comunicado,
                    valor_total_fact as valor,
                    'Sin datos de contacto' as motivo_exclusion
                FROM data_source_bascar
                WHERE run_id = ?
                    AND tipo_de_envio IS NULL
                ORDER BY id
                LIMIT ?
                OFFSET ?
            ", [$tipoComunicado, $run->id, $chunkSize, $offset]);

            foreach ($rows as $row) {
                $newContent .= sprintf(
                    "%s;%s;%s;%s;%s;%s\n",
                    $row->fecha_cruce,
                    $row->numero_id_aportante ?? '',
                    $row->periodo ?? '',
                    $row->tipo_comunicado,
                    $row->valor ?? '',
                    $row->motivo_exclusion
                );
                $processedRows++;
            }

            $offset += $chunkSize;
        }

        // Guardar archivo (append o create)
        $finalContent = $existingContent . $newContent;
        $disk->put($relativePath, $finalContent);
        $fileSize = $disk->size($relativePath);

        // Actualizar o crear registro en base de datos
        $existingFile = CollectionNoticeRunResultFile::where('collection_notice_run_id', $run->id)
            ->where('file_type', 'excluidos')
            ->first();

        if ($existingFile) {
            // Actualizar registro existente
            $existingFile->update([
                'size' => $fileSize,
                'records_count' => ($existingFile->records_count ?? 0) + $processedRows,
                'metadata' => array_merge($existingFile->metadata ?? [], [
                    'updated_at' => now()->toIso8601String(),
                    'sin_datos_contacto_added' => $processedRows,
                ]),
            ]);

            Log::info('✅ Archivo de excluidos actualizado', [
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
                'file_type' => 'excluidos',
                'file_name' => $fileName,
                'disk' => 'collection',
                'path' => $relativePath,
                'size' => $fileSize,
                'records_count' => $processedRows,
                'metadata' => [
                    'generated_at' => now()->toIso8601String(),
                    'step' => 'exclude_sin_datos_contacto',
                    'tipo_comunicado' => $tipoComunicado,
                ],
            ]);

            Log::info('✅ Archivo de excluidos creado', [
                'run_id' => $run->id,
                'file_path' => $relativePath,
                'records_count' => $processedRows,
                'size_kb' => round($fileSize / 1024, 2),
            ]);
        }
    }

    /**
     * Elimina registros sin datos de contacto de BASCAR.
     */
    private function deleteSinDatosContactoFromBascar(CollectionNoticeRun $run): int
    {
        Log::info('Eliminando registros sin datos de contacto de BASCAR', [
            'run_id' => $run->id,
        ]);

        $deleted = DB::delete("
            DELETE FROM data_source_bascar
            WHERE run_id = ?
                AND tipo_de_envio IS NULL
        ", [$run->id]);

        Log::info('✅ Registros sin datos de contacto eliminados de BASCAR', [
            'run_id' => $run->id,
            'deleted_count' => $deleted,
        ]);

        return $deleted;
    }
}
