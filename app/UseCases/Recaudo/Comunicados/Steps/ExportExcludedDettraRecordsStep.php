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
 * Step: Exportar registros excluidos de DETTRA (trabajadores que cruzaron con recaudo).
 *
 * Este step genera un archivo CSV con todos los trabajadores independientes que
 * fueron identificados como que ya realizaron el pago (cruzaron con PAGAPL, PAGLOG o PAGLOG_DV).
 *
 * Estos trabajadores NO deben recibir comunicado de mora, por lo que se exportan
 * como "excluidos" para auditoría y trazabilidad.
 *
 * Criterio de exclusión:
 * - observacion_trabajadores LIKE '%Cruza con recaudo%'
 *
 * Formato del archivo CSV:
 * - Nombre: excluidos_{run_id}.csv
 * - Separador: punto y coma (;)
 * - Columnas:
 *   1. FECHA_CRUCE: Fecha de ejecución del proceso (DD/MM/YYYY)
 *   2. NUMERO_ID_APORTANTE: NIT del trabajador independiente
 *   3. PERIODO: Periodo del run
 *   4. TIPO_COMUNICADO: Nombre del tipo de comunicado
 *   5. VALOR: Valor fijo 0 (no aplica para independientes)
 *   6. MOTIVO_EXCLUSION: Observación del trabajador
 */
final class ExportExcludedDettraRecordsStep implements ProcessingStepInterface
{
    public function __construct(
        private readonly FilesystemFactory $filesystem
    ) {
    }

    public function getName(): string
    {
        return 'Exportar registros excluidos de DETTRA (cruzan con recaudo)';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Exportando registros excluidos de DETTRA', ['run_id' => $run->id]);

        // Contar registros excluidos (que cruzaron con recaudo)
        $totalExcluidos = (int) DB::selectOne("
            SELECT COUNT(*) as count
            FROM data_source_dettra
            WHERE run_id = ?
                AND observacion_trabajadores LIKE ?
        ", [$run->id, '%Cruza con recaudo%'])->count;

        if ($totalExcluidos === 0) {
            Log::info('No hay registros excluidos para exportar', ['run_id' => $run->id]);
            return;
        }

        Log::info('Generando archivo de excluidos', [
            'run_id' => $run->id,
            'total_excluidos' => $totalExcluidos,
        ]);

        $filePath = $this->generateExcludedFileFromDB($run, $totalExcluidos);

        Log::info('Archivo de excluidos generado', [
            'run_id' => $run->id,
            'path' => $filePath,
            'total_registros' => $totalExcluidos,
        ]);
    }

    /**
     * Genera el archivo CSV de excluidos directamente desde la BD usando chunks.
     * Evita cargar todos los registros en memoria.
     *
     * @return string Ruta relativa del archivo generado
     */
    private function generateExcludedFileFromDB(CollectionNoticeRun $run, int $totalRecords): string
    {
        $fileName = sprintf('excluidos_%d.csv', $run->id);
        $relativeDir = sprintf('collection_notice_runs/%d/results', $run->id);
        $relativePath = $relativeDir . '/' . $fileName;

        $disk = $this->filesystem->disk('collection');

        if (!$disk->exists($relativeDir)) {
            $disk->makeDirectory($relativeDir);
        }

        $tipoComunicado = $run->type?->name ?? 'Sin tipo';
        $periodo = $run->period ?? '';

        // Encabezados del CSV
        $csvContent = "FECHA_CRUCE;NUMERO_ID_APORTANTE;PERIODO;TIPO_COMUNICADO;VALOR;MOTIVO_EXCLUSION\n";

        $chunkSize = 5000;
        $offset = 0;
        $processedRows = 0;

        // Procesar en chunks para evitar consumo excesivo de memoria
        while ($offset < $totalRecords) {
            $rows = DB::select("
                SELECT
                    TO_CHAR(NOW(), 'DD/MM/YYYY') as fecha_cruce,
                    nit as numero_id_aportante,
                    ? as periodo,
                    ? as tipo_comunicado,
                    0 as valor,
                    observacion_trabajadores as motivo_exclusion
                FROM data_source_dettra
                WHERE run_id = ?
                    AND observacion_trabajadores LIKE ?
                ORDER BY id
                LIMIT ?
                OFFSET ?
            ", [$periodo, $tipoComunicado, $run->id, '%Cruza con recaudo%', $chunkSize, $offset]);

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

        // Guardar archivo en disco
        $disk->put($relativePath, $csvContent);
        $fileSize = $disk->size($relativePath);

        // Registrar archivo en base de datos
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
                'step' => 'export_excluded_dettra_records',
                'tipo_comunicado' => $tipoComunicado,
                'periodo' => $periodo,
                'criterio_exclusion' => 'Cruza con recaudo',
            ],
        ]);

        Log::info('Archivo CSV generado y registrado', [
            'run_id' => $run->id,
            'file_name' => $fileName,
            'size_bytes' => $fileSize,
            'records' => $processedRows,
        ]);

        return $relativePath;
    }
}
