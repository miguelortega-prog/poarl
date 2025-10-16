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
 * Step: Excluir PSI con Persona Jurídica (9 dígitos).
 *
 * Identifica aportantes que tienen PSI = 'S' y NIT de 9 dígitos (persona jurídica)
 * y los excluye del proceso:
 *
 * Criterios de exclusión:
 * - psi = 'S' (mayúscula o minúscula)
 * - NUM_TOMADOR tiene exactamente 9 dígitos
 *
 * Acciones:
 * 1. Identifica registros que cumplen los criterios
 * 2. Los agrega al archivo de excluidos (excluidos{run_id}.csv)
 * 3. Elimina estos registros de data_source_bascar
 *
 * Motivo de exclusión: "PSI Persona Jurídica"
 */
final class ExcludePsiPersonaJuridicaStep implements ProcessingStepInterface
{
    public function __construct(
        private readonly FilesystemFactory $filesystem
    ) {
    }

    public function getName(): string
    {
        return 'Excluir PSI Persona Jurídica';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Excluyendo PSI Persona Jurídica', ['run_id' => $run->id]);

        $toExcludeCount = $this->countPsiPersonaJuridica($run);

        if ($toExcludeCount === 0) {
            Log::info('Exclusión de PSI Persona Jurídica completada', ['run_id' => $run->id]);
            return;
        }

        $this->appendToExcludedFile($run, $toExcludeCount);
        $this->deletePsiPersonaJuridicaFromBascar($run);

        Log::info('Exclusión de PSI Persona Jurídica completada', ['run_id' => $run->id]);
    }

    /**
     * Cuenta registros que cumplen criterio PSI Persona Jurídica.
     */
    private function countPsiPersonaJuridica(CollectionNoticeRun $run): int
    {
        return (int) DB::selectOne("
            SELECT COUNT(*) as count
            FROM data_source_bascar
            WHERE run_id = ?
                AND UPPER(psi) = 'S'
                AND LENGTH(num_tomador) = 9
                AND num_tomador IS NOT NULL
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
                    'PSI Persona Jurídica' as motivo_exclusion
                FROM data_source_bascar
                WHERE run_id = ?
                    AND UPPER(psi) = 'S'
                    AND LENGTH(num_tomador) = 9
                    AND num_tomador IS NOT NULL
                ORDER BY id
                LIMIT ?
                OFFSET ?
            ", [$tipoComunicado, $run->id, $chunkSize, $offset]);

            foreach ($rows as $row) {
                $newContent .= sprintf(
                    "%s;%s;%s;%s;%s;%s\n",
                    $row->fecha_cruce,
                    $row->numero_id_aportante,
                    $row->periodo,
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
                    'psi_persona_juridica_added' => $processedRows,
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
                    'step' => 'exclude_psi_persona_juridica',
                    'tipo_comunicado' => $tipoComunicado,
                ],
            ]);
        }
    }

    /**
     * Elimina registros PSI Persona Jurídica de BASCAR.
     */
    private function deletePsiPersonaJuridicaFromBascar(CollectionNoticeRun $run): int
    {
        $deleted = DB::delete("
            DELETE FROM data_source_bascar
            WHERE run_id = ?
                AND UPPER(psi) = 'S'
                AND LENGTH(num_tomador) = 9
                AND num_tomador IS NOT NULL
        ", [$run->id]);

        return $deleted;
    }
}
