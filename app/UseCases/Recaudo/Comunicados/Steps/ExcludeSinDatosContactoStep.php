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
        Log::info('Excluyendo registros sin datos de contacto', ['run_id' => $run->id]);

        $toExcludeCount = $this->countSinDatosContacto($run);

        if ($toExcludeCount === 0) {
            Log::info('Exclusión de registros sin datos de contacto completada', ['run_id' => $run->id]);
            return;
        }

        $this->appendToExcludedFile($run, $toExcludeCount);
        $this->deleteSinDatosContactoFromBascar($run);

        Log::info('Exclusión de registros sin datos de contacto completada', ['run_id' => $run->id]);
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

        $fileExists = $disk->exists($relativePath);
        $existingContent = $fileExists ? $disk->get($relativePath) : '';

        $tipoComunicado = $run->type?->name ?? 'Sin tipo';
        $newContent = '';

        if (!$fileExists) {
            if (!$disk->exists($relativeDir)) {
                $disk->makeDirectory($relativeDir);
            }
            $newContent .= "FECHA_CRUCE;NUMERO_ID_APORTANTE;PERIODO;TIPO_COMUNICADO;VALOR;MOTIVO_EXCLUSION\n";
        }
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

        $finalContent = $existingContent . $newContent;
        $disk->put($relativePath, $finalContent);
        $fileSize = $disk->size($relativePath);

        $existingFile = CollectionNoticeRunResultFile::where('collection_notice_run_id', $run->id)
            ->where('file_type', 'excluidos')
            ->first();

        if ($existingFile) {
            $existingFile->update([
                'size' => $fileSize,
                'records_count' => ($existingFile->records_count ?? 0) + $processedRows,
                'metadata' => array_merge($existingFile->metadata ?? [], [
                    'updated_at' => now()->toIso8601String(),
                    'sin_datos_contacto_added' => $processedRows,
                ]),
            ]);
        } else {
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
        }
    }

    /**
     * Elimina registros sin datos de contacto de BASCAR.
     */
    private function deleteSinDatosContactoFromBascar(CollectionNoticeRun $run): int
    {
        $deleted = DB::delete("
            DELETE FROM data_source_bascar
            WHERE run_id = ?
                AND tipo_de_envio IS NULL
        ", [$run->id]);

        return $deleted;
    }
}
