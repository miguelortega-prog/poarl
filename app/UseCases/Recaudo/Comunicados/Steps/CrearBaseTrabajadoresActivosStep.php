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
 * - Busca registros de DETTRA cuyo NRO_DOCUMTO exista en BASCAR.NUM_TOMADOR
 * - Genera archivo CSV "Detalle de Trabajadores" con información detallada
 *
 * Cruce:
 * DETTRA.NRO_DOCUMTO = BASCAR.NUM_TOMADOR
 *
 * Output: detalle_trabajadores{run_id}.csv
 */
final class CrearBaseTrabajadoresActivosStep implements ProcessingStepInterface
{
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
        $startTime = microtime(true);

        Log::info('👷 Creando base de trabajadores activos', [
            'step' => self::class,
            'run_id' => $run->id,
            'period' => $run->period,
        ]);

        // Contar registros que cruzan
        $totalRecords = $this->countMatchingRecords($run);

        if ($totalRecords === 0) {
            Log::warning('No hay registros de DETTRA que crucen con BASCAR', [
                'run_id' => $run->id,
            ]);
            return;
        }

        Log::info('Registros de trabajadores activos encontrados', [
            'run_id' => $run->id,
            'total' => $totalRecords,
        ]);

        // Generar archivo CSV
        $filePath = $this->generateWorkerDetailFile($run, $totalRecords);

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('✅ Base de trabajadores activos creada', [
            'run_id' => $run->id,
            'file_path' => $filePath,
            'records_count' => $totalRecords,
            'duration_ms' => $duration,
        ]);
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
                ON d.NRO_DOCUMTO = b.NUM_TOMADOR
            WHERE d.run_id = ?
                AND b.run_id = ?
                AND d.NRO_DOCUMTO IS NOT NULL
                AND b.NUM_TOMADOR IS NOT NULL
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

        // Crear directorio si no existe
        if (!$disk->exists($relativeDir)) {
            $disk->makeDirectory($relativeDir);
        }

        Log::info('Generando archivo de detalle de trabajadores', [
            'run_id' => $run->id,
            'total_records' => $totalRecords,
            'file' => $fileName,
        ]);

        // Extraer año y mes del período (formato YYYYMM)
        $period = $run->period;
        $year = substr($period, 0, 4);
        $month = substr($period, 4, 2);

        // Generar CSV con encabezado
        $csvContent = "TPO_IDEN_TRABAJADOR;NRO_IDEN;AÑO;MES;TPO_EMP;NRO_IDVI;CLS_RICT;FCH_INVI;PÓLIZA;VALOR;TPO_COT;FCH_FIN;TRAB_EXPUESTOS\n";

        // Procesar en chunks
        $chunkSize = 5000;
        $offset = 0;
        $processedRows = 0;

        while ($offset < $totalRecords) {
            $rows = DB::select("
                SELECT
                    d.TIPO_DOC as tpo_iden_trabajador,
                    d.NIT as nro_iden,
                    ? as anio,
                    ? as mes,
                    b.IDENT_ASEGURADO as tpo_emp,
                    d.NRO_DOCUMTO as nro_idvi,
                    d.RIESGO as cls_rict,
                    d.FECHA_INI_COBERT as fch_invi,
                    b.NUM_POLI as poliza,
                    b.VALOR_TOTAL_FACT as valor_total_fact,
                    b.cantidad_trabajadores,
                    d.TIPO_COTIZANTE as tpo_cot
                FROM data_source_dettra d
                INNER JOIN data_source_bascar b
                    ON d.NRO_DOCUMTO = b.NUM_TOMADOR
                WHERE d.run_id = ?
                    AND b.run_id = ?
                    AND d.NRO_DOCUMTO IS NOT NULL
                    AND b.NUM_TOMADOR IS NOT NULL
                ORDER BY d.id
                LIMIT ?
                OFFSET ?
            ", [$year, $month, $run->id, $run->id, $chunkSize, $offset]);

            foreach ($rows as $row) {
                // Convertir riesgo a número romano
                $riesgoRomano = $this->convertToRoman((int) $row->cls_rict);

                // Formatear fecha sin guiones
                $fechaInvi = $this->formatDateWithoutDashes($row->fch_invi);

                // Calcular valor (división con protección contra cero)
                $valor = 0;
                if ($row->cantidad_trabajadores > 0) {
                    $valor = round($row->valor_total_fact / $row->cantidad_trabajadores, 2);
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
                    'NO REGISTRA',
                    $row->cantidad_trabajadores ?? 0
                );
                $processedRows++;
            }

            $offset += $chunkSize;

            if ($offset % 10000 === 0) {
                Log::debug('Progreso generación de detalle de trabajadores', [
                    'run_id' => $run->id,
                    'processed' => $processedRows,
                    'total' => $totalRecords,
                    'percent' => round(($processedRows / $totalRecords) * 100, 1),
                ]);
            }
        }

        // Guardar archivo
        $disk->put($relativePath, $csvContent);
        $fileSize = $disk->size($relativePath);

        // Registrar archivo en base de datos
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

        Log::info('✅ Archivo de detalle de trabajadores generado', [
            'run_id' => $run->id,
            'file_path' => $relativePath,
            'records_count' => $processedRows,
            'size_kb' => round($fileSize / 1024, 2),
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
            default => (string) $number, // Fallback por si hay valores fuera de rango
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
