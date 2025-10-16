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
 * Step: Crear Base de Trabajadores Activos.
 *
 * Realiza un cruce inverso entre DETTRA y BASCAR:
 * - Busca registros de DETTRA cuyo nro_documto exista en BASCAR.num_tomador
 * - Genera archivo CSV "Detalle de Trabajadores" con información detallada
 *
 * Cruce:
 * DETTRA.nro_documto = BASCAR.num_tomador
 *
 * Output: detalle_trabajadores{run_id}.csv
 */
final class CrearBaseTrabajadoresActivosStep implements ProcessingStepInterface
{
    /**
     * Constante para valor fijo FCH_FIN.
     */
    private const FCH_FIN = 'NO REGISTRA';

    public function __construct(
        private readonly FilesystemFactory $filesystem
    ) {
    }

    public function getName(): string
    {
        return 'Crear Base de Trabajadores Activos';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Creando base de trabajadores activos', ['run_id' => $run->id]);

        $totalRecords = $this->countMatchingRecords($run);

        if ($totalRecords === 0) {
            Log::info('Base de trabajadores activos creada', ['run_id' => $run->id]);
            return;
        }

        $this->generateWorkerDetailFile($run, $totalRecords);

        Log::info('Base de trabajadores activos creada', ['run_id' => $run->id]);
    }

    /**
     * Cuenta registros de DETTRA que cruzan con BASCAR.
     */
    private function countMatchingRecords(CollectionNoticeRun $run): int
    {
        return (int) DB::selectOne("
            SELECT COUNT(*) as count
            FROM data_source_dettra d
            INNER JOIN data_source_bascar b
                ON d.nro_documto = b.num_tomador
            WHERE d.run_id = ?
                AND b.run_id = ?
                AND d.nro_documto IS NOT NULL
                AND b.num_tomador IS NOT NULL
        ", [$run->id, $run->id])->count;
    }

    /**
     * Genera el archivo CSV de detalle de trabajadores.
     */
    private function generateWorkerDetailFile(CollectionNoticeRun $run, int $totalRecords): string
    {
        $fileName = sprintf('detalle_trabajadores%d.csv', $run->id);
        $relativeDir = sprintf('collection_notice_runs/%d/results', $run->id);
        $relativePath = $relativeDir . '/' . $fileName;

        $disk = $this->filesystem->disk('collection');

        if (!$disk->exists($relativeDir)) {
            $disk->makeDirectory($relativeDir);
        }

        $period = $run->period;
        $year = substr($period, 0, 4);
        $month = substr($period, 4, 2);

        $csvContent = "TPO_IDEN_TRABAJADOR;NRO_IDEN;AÑO;MES;TPO_EMP;NRO_IDVI;CLS_RICT;FCH_INVI;PÓLIZA;VALOR;TPO_COT;FCH_FIN;TRAB_EXPUESTOS\n";

        $chunkSize = 5000;
        $offset = 0;
        $processedRows = 0;

        while ($offset < $totalRecords) {
            $rows = DB::select("
                SELECT
                    d.tipo_doc as tpo_iden_trabajador,
                    d.nit as nro_iden,
                    ? as anio,
                    ? as mes,
                    b.ident_asegurado as tpo_emp,
                    d.nro_documto as nro_idvi,
                    d.riesgo as cls_rict,
                    d.fecha_ini_cobert as fch_invi,
                    b.num_poliza as poliza,
                    b.valor_total_fact,
                    b.cantidad_trabajadores,
                    d.tipo_cotizante as tpo_cot
                FROM data_source_dettra d
                INNER JOIN data_source_bascar b
                    ON d.nro_documto = b.num_tomador
                WHERE d.run_id = ?
                    AND b.run_id = ?
                    AND d.nro_documto IS NOT NULL
                    AND b.num_tomador IS NOT NULL
                ORDER BY d.id
                LIMIT ?
                OFFSET ?
            ", [$year, $month, $run->id, $run->id, $chunkSize, $offset]);

            foreach ($rows as $row) {
                $riesgoRomano = $this->convertToRoman((int) $row->cls_rict);
                $fechaInvi = $this->formatDateWithoutDashes($row->fch_invi);

                $valor = 0;
                $valorTotalFact = (float) ($row->valor_total_fact ?? 0);
                $cantidadTrabajadores = (int) ($row->cantidad_trabajadores ?? 0);

                if ($cantidadTrabajadores > 0 && $valorTotalFact > 0) {
                    $valor = round($valorTotalFact / $cantidadTrabajadores, 2);
                }

                $csvContent .= sprintf(
                    "%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s\n",
                    $row->tpo_iden_trabajador ?? '',
                    $row->nro_iden ?? '',
                    $row->anio,
                    $row->mes,
                    $row->tpo_emp ?? '',
                    $row->nro_idvi ?? '',
                    $riesgoRomano,
                    $fechaInvi,
                    $row->poliza ?? '',
                    $valor,
                    $row->tpo_cot ?? '',
                    self::FCH_FIN,
                    $row->cantidad_trabajadores ?? 0
                );
                $processedRows++;
            }

            $offset += $chunkSize;
        }

        $disk->put($relativePath, $csvContent);
        $fileSize = $disk->size($relativePath);

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
                'step' => 'crear_base_trabajadores_activos',
                'period' => $run->period,
                'year' => $year,
                'month' => $month,
            ],
        ]);

        return $relativePath;
    }

    /**
     * Convierte un número arábigo (1-5) a número romano.
     */
    private function convertToRoman(int $number): string
    {
        return match ($number) {
            1 => 'I',
            2 => 'II',
            3 => 'III',
            4 => 'IV',
            5 => 'V',
            default => 'I', // Fallback por si hay valores fuera de rango
        };
    }

    /**
     * Formatea una fecha removiendo guiones.
     *
     * Entrada: 2024-01-15 o 2024/01/15
     * Salida: 20240115
     */
    private function formatDateWithoutDashes(?string $date): string
    {
        if ($date === null) {
            return '';
        }

        // Remover guiones y slashes
        return str_replace(['-', '/'], '', $date);
    }
}
