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
 * Step: Cruzar BASCAR con PAGAPL para identificar aportantes que ya pagaron.
 *
 * Realiza INNER JOIN directo entre las tablas usando composite_key.
 * Los registros que coinciden (aportantes que pagaron) se guardan en excluidos{run_id}.csv
 *
 * Lógica:
 * - BASCAR = Base de cartera (aportantes con deuda)
 * - PAGAPL = Pagos aplicados
 * - Si BASCAR.composite_key existe en PAGAPL → el aportante ya pagó → EXCLUIR
 *
 * Output: excluidos{run_id}.csv con aportantes que NO deben recibir comunicado
 */
final class CrossBascarWithPagaplStep implements ProcessingStepInterface
{
    public function __construct(
        private readonly FilesystemFactory $filesystem
    ) {
    }

    public function getName(): string
    {
        return 'Cruzar BASCAR con PAGAPL';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Cruzando BASCAR con PAGAPL', ['run_id' => $run->id]);

        $coincidencias = (int) DB::selectOne("
            SELECT COUNT(DISTINCT b.id) as count
            FROM data_source_bascar b
            INNER JOIN data_source_pagapl p
                ON b.composite_key = p.composite_key
            WHERE b.run_id = ?
                AND p.run_id = ?
        ", [$run->id, $run->id])->count;

        if ($coincidencias > 0) {
            $this->generateExcludedFileFromDB($run, $coincidencias);
        }

        Log::info('Cruce BASCAR-PAGAPL completado', ['run_id' => $run->id]);
    }


    /**
     * Genera el archivo CSV de excluidos directamente desde la BD usando chunks.
     * Evita cargar todos los registros en memoria.
     */
    private function generateExcludedFileFromDB(CollectionNoticeRun $run, int $totalRecords): string
    {
        $fileName = sprintf('excluidos%d.csv', $run->id);
        $relativeDir = sprintf('collection_notice_runs/%d/results', $run->id);
        $relativePath = $relativeDir . '/' . $fileName;

        $disk = $this->filesystem->disk('collection');

        if (!$disk->exists($relativeDir)) {
            $disk->makeDirectory($relativeDir);
        }

        $tipoComunicado = $run->type?->name ?? 'Sin tipo';
        $csvContent = "FECHA_CRUCE;NUMERO_ID_APORTANTE;PERIODO;TIPO_COMUNICADO;VALOR;MOTIVO_EXCLUSION\n";

        $chunkSize = 5000;
        $offset = 0;
        $processedRows = 0;

        while ($offset < $totalRecords) {
            $rows = DB::select("
                SELECT
                    TO_CHAR(NOW(), 'DD/MM/YYYY') as fecha_cruce,
                    b.num_tomador as numero_id_aportante,
                    b.periodo,
                    ? as tipo_comunicado,
                    b.valor_total_fact as valor,
                    'Cruza con recaudo' as motivo_exclusion
                FROM data_source_bascar b
                INNER JOIN data_source_pagapl p
                    ON b.composite_key = p.composite_key
                WHERE b.run_id = ?
                    AND p.run_id = ?
                ORDER BY b.id
                LIMIT ?
                OFFSET ?
            ", [$tipoComunicado, $run->id, $run->id, $chunkSize, $offset]);

            foreach ($rows as $row) {
                $csvContent .= sprintf(
                    "%s;%s;%s;%s;%s;%s\n",
                    $row->fecha_cruce,
                    $row->numero_id_aportante,
                    $row->periodo,
                    $row->tipo_comunicado,
                    $row->valor,
                    $row->motivo_exclusion
                );
                $processedRows++;
            }

            $offset += $chunkSize;
        }

        $disk->put($relativePath, $csvContent);
        $fileSize = $disk->size($relativePath);

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
                'step' => 'cross_bascar_pagapl',
                'tipo_comunicado' => $tipoComunicado,
            ],
        ]);

        return $relativePath;
    }
}
