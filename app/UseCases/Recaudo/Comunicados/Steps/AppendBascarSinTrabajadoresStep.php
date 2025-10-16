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
 * - FCH_FIN: 'NO REGISTRA' (valor fijo)
 * - TRAB_EXPUESTOS: 1 (valor fijo)
 */
final class AppendBascarSinTrabajadoresStep implements ProcessingStepInterface
{
    /**
     * Constantes para valores fijos en el archivo de detalle.
     */
    private const CLS_RICT = 'I';               // Número romano 1
    private const FCH_INVI = '01010001';        // Fecha de inicio de vigencia fija
    private const TPO_COT = '0';                // Tipo de cotizante
    private const FCH_FIN = 'NO REGISTRA';      // Fecha fin (sin registro)
    private const TRAB_EXPUESTOS = 1;           // Trabajadores expuestos por defecto
    private const NRO_IDVI_DEFAULT = '';        // NRO_IDVI pendiente de definir

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
        Log::info('Agregando registros de BASCAR sin trabajadores activos', ['run_id' => $run->id]);

        $totalRecords = $this->countBascarWithoutWorkers($run);

        if ($totalRecords === 0) {
            Log::info('Registros sin trabajadores agregados al detalle', ['run_id' => $run->id]);
            return;
        }

        $this->appendToWorkerDetailFile($run, $totalRecords);

        Log::info('Registros sin trabajadores agregados al detalle', ['run_id' => $run->id]);
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

        if (!$disk->exists($relativePath)) {
            if (!$disk->exists($relativeDir)) {
                $disk->makeDirectory($relativeDir);
            }
            $disk->put($relativePath, "TPO_IDEN_TRABAJADOR;NRO_IDEN;AÑO;MES;TPO_EMP;NRO_IDVI;CLS_RICT;FCH_INVI;PÓLIZA;VALOR;TPO_COT;FCH_FIN;TRAB_EXPUESTOS\n");
        }

        $existingContent = $disk->get($relativePath);

        $period = $run->period;
        $year = substr($period, 0, 4);
        $month = substr($period, 4, 2);

        $newContent = '';
        $chunkSize = 5000;
        $offset = 0;
        $processedRows = 0;

        while ($offset < $totalRecords) {
            $rows = DB::select("
                SELECT
                    ident_asegurado,
                    num_tomador,
                    num_poliza,
                    valor_total_fact
                FROM data_source_bascar
                WHERE run_id = ?
                    AND observacion_trabajadores = 'Sin trabajadores activos'
                ORDER BY id
                LIMIT ?
                OFFSET ?
            ", [$run->id, $chunkSize, $offset]);

            foreach ($rows as $row) {
                $poliza = $row->num_poliza ?? '';

                $newContent .= sprintf(
                    "%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s\n",
                    $row->ident_asegurado ?? '',    // TPO_IDEN_TRABAJADOR
                    $row->num_tomador ?? '',         // NRO_IDEN
                    $year,                           // AÑO
                    $month,                          // MES
                    $row->ident_asegurado ?? '',    // TPO_EMP
                    $row->num_tomador ?? '',        // NRO_IDVI mismo del tomador
                    self::CLS_RICT,                 // CLS_RICT (número romano 1)
                    self::FCH_INVI,                 // FCH_INVI (valor fijo)
                    $poliza,                         // PÓLIZA
                    $row->valor_total_fact ?? 0,    // VALOR
                    self::TPO_COT,                  // TPO_COT (valor fijo)
                    self::FCH_FIN,                  // FCH_FIN (valor fijo)
                    self::TRAB_EXPUESTOS            // TRAB_EXPUESTOS (valor fijo)
                );
                $processedRows++;
            }

            $offset += $chunkSize;
        }

        $finalContent = $existingContent . $newContent;
        $disk->put($relativePath, $finalContent);
        $fileSize = $disk->size($relativePath);

        $existingFile = CollectionNoticeRunResultFile::where('collection_notice_run_id', $run->id)
            ->where('file_type', 'detalle_trabajadores')
            ->first();

        if ($existingFile) {
            $existingFile->update([
                'size' => $fileSize,
                'records_count' => ($existingFile->records_count ?? 0) + $processedRows,
                'metadata' => array_merge($existingFile->metadata ?? [], [
                    'updated_at' => now()->toIso8601String(),
                    'bascar_sin_trabajadores_added' => $processedRows,
                ]),
            ]);
        } else {
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
        }
    }
}
