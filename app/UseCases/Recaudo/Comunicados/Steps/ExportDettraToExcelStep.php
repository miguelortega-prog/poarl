<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use App\Models\CollectionNoticeRunResultFile;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;

/**
 * Step: Exportar DETTRA a Excel 97 (.xls).
 *
 * Genera archivos Excel 97 (.xls) con 2 hojas para trabajadores independientes:
 *
 * Hoja 1 (Independientes): Data de DETTRA con campos específicos del trabajador
 * Hoja 2 (Pendiente): Información adicional que se indicará
 *
 * Límite Excel 97: 65,536 filas por hoja (65,535 datos + 1 encabezado)
 * Si se supera el límite, crea archivos adicionales (_parte2, _parte3, etc.)
 *
 * Cálculo de tasa de riesgo:
 * - Riesgo 1: 0.55%
 * - Riesgo 2: 1.044%
 * - Riesgo 3: 2.44%
 * - Riesgo 4: 4.35%
 * - Riesgo 5: 6.96%
 *
 * Nombre: Constitucion_en_mora_independientes_{{periodo}}.xls
 */
final class ExportDettraToExcelStep implements ProcessingStepInterface
{
    private const MAX_ROWS_PER_SHEET = 65535; // 65,536 - 1 header

    // Tasas de riesgo ARL
    private const TASA_RIESGO = [
        '1' => 0.0055,   // 0.55%
        '2' => 0.01044,  // 1.044%
        '3' => 0.0244,   // 2.44%
        '4' => 0.0435,   // 4.35%
        '5' => 0.0696,   // 6.96%
    ];

    public function __construct(
        private readonly FilesystemFactory $filesystem
    ) {
    }

    public function getName(): string
    {
        return 'Exportar DETTRA a Excel';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Exportando DETTRA a Excel', ['run_id' => $run->id]);

        $dettraCount = $this->countDettraRecords($run);

        if ($dettraCount === 0) {
            Log::warning('No hay registros en DETTRA para exportar', ['run_id' => $run->id]);
            return;
        }

        $filesNeeded = max(
            (int) ceil($dettraCount / self::MAX_ROWS_PER_SHEET),
            1
        );

        for ($fileIndex = 1; $fileIndex <= $filesNeeded; $fileIndex++) {
            $this->generateExcelFile($run, $fileIndex, $filesNeeded, $dettraCount);
        }

        Log::info('Exportación DETTRA a Excel completada', [
            'run_id' => $run->id,
            'total_registros' => $dettraCount,
            'archivos_generados' => $filesNeeded,
        ]);
    }

    /**
     * Cuenta registros de DETTRA.
     */
    private function countDettraRecords(CollectionNoticeRun $run): int
    {
        return (int) DB::selectOne("
            SELECT COUNT(*) as count
            FROM data_source_dettra
            WHERE run_id = ?
        ", [$run->id])->count;
    }

    /**
     * Genera un archivo Excel.
     */
    private function generateExcelFile(
        CollectionNoticeRun $run,
        int $fileIndex,
        int $totalFiles,
        int $dettraCount
    ): void {
        $spreadsheet = new Spreadsheet();
        $this->generateSheet1($spreadsheet, $run, $fileIndex);
        $this->generateSheet2($spreadsheet, $run, $fileIndex);
        $this->saveExcelFile($spreadsheet, $run, $fileIndex, $totalFiles);
    }

    /**
     * Genera Hoja 1 (Independientes) con data de DETTRA.
     */
    private function generateSheet1(Spreadsheet $spreadsheet, CollectionNoticeRun $run, int $fileIndex): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Independientes');

        // Encabezados
        $headers = [
            'NIT',
            'REPRESENTANTE LEGAL',
            'CORREO',
            'CÉDULA',
            'NOMBRE EMPRESA',
            'CARGO',
            'DIRECCIÓN',
            'CIUDAD',
            'Contrato',
            'AÑO1',
            'MES1',
            'VALOR1',
            'AFILIADOS1',
            'CONSECUTIVO',
            'TIPO IND',
            'COD CIUDAD',
        ];

        // Escribir encabezados
        $sheet->fromArray($headers, null, 'A1');

        // Calcular offset y limit
        $offset = ($fileIndex - 1) * self::MAX_ROWS_PER_SHEET;
        $limit = self::MAX_ROWS_PER_SHEET;

        // Extraer año y mes del periodo
        $periodoAnio = substr($run->period ?? '', 0, 4);
        $periodoMes = substr($run->period ?? '', 4, 2);

        // Obtener data de DETTRA
        $dettraData = DB::select("
            SELECT
                d.nit,
                d.nombres as representante_legal,
                d.correo,
                COALESCE(r.official_id, '') as cedula,
                d.nombres as nombre_empresa,
                'CONTRATISTA INDEPENDIENTE' as cargo,
                d.direccion,
                d.codigo_ciudad as ciudad,
                d.num_poli as contrato,
                ? as anio1,
                ? as mes1,
                CASE
                    WHEN d.riesgo = '1' THEN ROUND(CAST(d.salario AS NUMERIC) * ?, 0)
                    WHEN d.riesgo = '2' THEN ROUND(CAST(d.salario AS NUMERIC) * ?, 0)
                    WHEN d.riesgo = '3' THEN ROUND(CAST(d.salario AS NUMERIC) * ?, 0)
                    WHEN d.riesgo = '4' THEN ROUND(CAST(d.salario AS NUMERIC) * ?, 0)
                    WHEN d.riesgo = '5' THEN ROUND(CAST(d.salario AS NUMERIC) * ?, 0)
                    ELSE 0
                END as valor1,
                1 as afiliados1,
                d.consecutivo,
                d.tipo_doc as tipo_ind,
                d.codigo_ciudad as cod_ciudad
            FROM data_source_dettra d
            INNER JOIN collection_notice_runs r ON d.run_id = r.id
            WHERE d.run_id = ?
            ORDER BY d.id
            LIMIT ? OFFSET ?
        ", [
            $periodoAnio,
            $periodoMes,
            self::TASA_RIESGO['1'],
            self::TASA_RIESGO['2'],
            self::TASA_RIESGO['3'],
            self::TASA_RIESGO['4'],
            self::TASA_RIESGO['5'],
            $run->id,
            $limit,
            $offset,
        ]);

        // Escribir datos
        $row = 2; // Empezar en fila 2 (después del encabezado)
        foreach ($dettraData as $data) {
            $sheet->fromArray([
                $data->nit,
                $data->representante_legal,
                $data->correo,
                $data->cedula,
                $data->nombre_empresa,
                $data->cargo,
                $data->direccion,
                $data->ciudad,
                $data->contrato,
                $data->anio1,
                $data->mes1,
                $data->valor1,
                $data->afiliados1,
                $data->consecutivo,
                $data->tipo_ind,
                $data->cod_ciudad,
            ], null, 'A' . $row);
            $row++;
        }

        Log::info('Hoja 1 (Independientes) generada', [
            'run_id' => $run->id,
            'file_index' => $fileIndex,
            'registros_escritos' => count($dettraData),
        ]);
    }

    /**
     * Genera Hoja 2 (Trabajadores Expuestos) con detalle de DETTRA.
     *
     * Convierte riesgo numérico a romano:
     * - 1 → I
     * - 2 → II
     * - 3 → III
     * - 4 → IV
     * - 5 → V
     */
    private function generateSheet2(Spreadsheet $spreadsheet, CollectionNoticeRun $run, int $fileIndex): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Trabajadores Expuestos');

        // Encabezados
        $headers = [
            'TPO_IDEN. TRABAJADOR',
            'NRO_IDEN',
            'AÑO',
            'MES',
            'TPO_EMP',
            'NRO_IDVI',
            'CLS_RICT',
            'FCH_INV',
            'PÓLIZA',
            'VALOR',
            'TPO_COT',
            'FCH_FIN',
            'TRAB_EXPUESTOS',
        ];

        // Escribir encabezados
        $sheet->fromArray($headers, null, 'A1');

        // Calcular offset y limit
        $offset = ($fileIndex - 1) * self::MAX_ROWS_PER_SHEET;
        $limit = self::MAX_ROWS_PER_SHEET;

        // Extraer año y mes del periodo
        $periodoAnio = substr($run->period ?? '', 0, 4);
        $periodoMes = substr($run->period ?? '', 4, 2);

        // Obtener data de DETTRA
        $dettraData = DB::select("
            SELECT
                d.tipo_doc as tpo_iden_trabajador,
                d.nit as nro_iden,
                ? as anio,
                ? as mes,
                d.tipo_doc as tpo_emp,
                d.nit as nro_idvi,
                CASE
                    WHEN d.riesgo = '1' THEN 'I'
                    WHEN d.riesgo = '2' THEN 'II'
                    WHEN d.riesgo = '3' THEN 'III'
                    WHEN d.riesgo = '4' THEN 'IV'
                    WHEN d.riesgo = '5' THEN 'V'
                    ELSE d.riesgo
                END as cls_rict,
                REPLACE(COALESCE(d.fecha_ini_cobert, ''), '-', '') as fch_inv,
                d.num_poli as poliza,
                CASE
                    WHEN d.riesgo = '1' THEN ROUND(CAST(d.salario AS NUMERIC) * ?, 0)
                    WHEN d.riesgo = '2' THEN ROUND(CAST(d.salario AS NUMERIC) * ?, 0)
                    WHEN d.riesgo = '3' THEN ROUND(CAST(d.salario AS NUMERIC) * ?, 0)
                    WHEN d.riesgo = '4' THEN ROUND(CAST(d.salario AS NUMERIC) * ?, 0)
                    WHEN d.riesgo = '5' THEN ROUND(CAST(d.salario AS NUMERIC) * ?, 0)
                    ELSE 0
                END as valor,
                d.tipo_cotizante as tpo_cot,
                'NO REGISTRA' as fch_fin,
                1 as trab_expuestos
            FROM data_source_dettra d
            WHERE d.run_id = ?
            ORDER BY d.id
            LIMIT ? OFFSET ?
        ", [
            $periodoAnio,
            $periodoMes,
            self::TASA_RIESGO['1'],
            self::TASA_RIESGO['2'],
            self::TASA_RIESGO['3'],
            self::TASA_RIESGO['4'],
            self::TASA_RIESGO['5'],
            $run->id,
            $limit,
            $offset,
        ]);

        // Escribir datos
        $row = 2; // Empezar en fila 2 (después del encabezado)
        foreach ($dettraData as $data) {
            $sheet->fromArray([
                $data->tpo_iden_trabajador,
                $data->nro_iden,
                $data->anio,
                $data->mes,
                $data->tpo_emp,
                $data->nro_idvi,
                $data->cls_rict,
                $data->fch_inv,
                $data->poliza,
                $data->valor,
                $data->tpo_cot,
                $data->fch_fin,
                $data->trab_expuestos,
            ], null, 'A' . $row);
            $row++;
        }

        Log::info('Hoja 2 (Trabajadores Expuestos) generada', [
            'run_id' => $run->id,
            'file_index' => $fileIndex,
            'registros_escritos' => count($dettraData),
        ]);
    }

    /**
     * Guarda el archivo Excel 97 (.xls).
     */
    private function saveExcelFile(
        Spreadsheet $spreadsheet,
        CollectionNoticeRun $run,
        int $fileIndex,
        int $totalFiles
    ): string {
        // Nombre del archivo con periodo
        $baseName = sprintf('Constitucion_en_mora_independientes_%s', $run->period);

        // Agregar sufijo si hay múltiples archivos
        if ($totalFiles > 1) {
            $fileName = sprintf('%s_parte%d.xls', $baseName, $fileIndex);
        } else {
            $fileName = $baseName . '.xls';
        }

        $relativeDir = sprintf('collection_notice_runs/%d/results', $run->id);
        $relativePath = $relativeDir . '/' . $fileName;

        $disk = $this->filesystem->disk('collection');

        // Crear directorio si no existe
        if (!$disk->exists($relativeDir)) {
            $disk->makeDirectory($relativeDir);
        }

        // Guardar archivo temporal
        $tempPath = sys_get_temp_dir() . '/' . uniqid('excel_export_', true) . '.xls';

        // Escribir Excel 97 (.xls)
        $writer = new Xls($spreadsheet);
        $writer->save($tempPath);

        // Subir a disco
        $disk->put($relativePath, file_get_contents($tempPath));

        // Eliminar temporal
        unlink($tempPath);

        // Corregir permisos del archivo (por si se creó como root)
        \App\Services\Recaudo\Comunicados\BaseCollectionNoticeProcessor::fixFilePermissions(
            $disk->path($relativePath)
        );

        $fileSize = $disk->size($relativePath);

        // Contar registros en DETTRA para este archivo
        $offset = ($fileIndex - 1) * self::MAX_ROWS_PER_SHEET;
        $recordsCount = (int) DB::selectOne("
            SELECT COUNT(*) as count
            FROM data_source_dettra
            WHERE run_id = ?
            LIMIT ? OFFSET ?
        ", [$run->id, self::MAX_ROWS_PER_SHEET, $offset])->count;

        // Registrar en BD
        CollectionNoticeRunResultFile::create([
            'collection_notice_run_id' => $run->id,
            'file_type' => 'comunicado_excel_independientes',
            'file_name' => $fileName,
            'disk' => 'collection',
            'path' => $relativePath,
            'size' => $fileSize,
            'records_count' => $recordsCount,
            'metadata' => [
                'generated_at' => now()->toIso8601String(),
                'step' => 'export_dettra_to_excel',
                'format' => 'xls',
                'sheets' => 2,
                'file_index' => $fileIndex,
                'total_files' => $totalFiles,
                'periodo' => $run->period,
            ],
        ]);

        Log::info('Archivo Excel guardado', [
            'run_id' => $run->id,
            'file_name' => $fileName,
            'size' => $fileSize,
            'records' => $recordsCount,
        ]);

        return $relativePath;
    }
}
