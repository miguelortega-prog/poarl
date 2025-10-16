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
 * Genera 2 archivos Excel 97 (.xls) separados por tipo_cotizante:
 * - Archivo 1: tipo_cotizante = '16' (Tipo especial)
 * - Archivo 2: tipo_cotizante != '16' (Tipos regulares: 3, 59, etc.)
 *
 * Cada archivo tiene 2 hojas:
 * - Hoja 1 (Independientes): Data de DETTRA con campos específicos del trabajador
 * - Hoja 2 (Trabajadores Expuestos): Detalle con riesgo en números romanos
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
 * Nombres:
 * - Constitucion_en_mora_independientes_tipo16_{{periodo}}.xls
 * - Constitucion_en_mora_independientes_{{periodo}}.xls
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

        // Contar registros por tipo_cotizante
        $countTipo16 = $this->countDettraRecordsByTipo($run, '16');
        $countOtros = $this->countDettraRecordsByTipo($run, 'otros');

        $totalRecords = $countTipo16 + $countOtros;

        if ($totalRecords === 0) {
            Log::warning('No hay registros en DETTRA para exportar', ['run_id' => $run->id]);
            return;
        }

        Log::info('Registros DETTRA por tipo_cotizante', [
            'run_id' => $run->id,
            'tipo_16' => $countTipo16,
            'otros_tipos' => $countOtros,
            'total' => $totalRecords,
        ]);

        $totalFilesGenerated = 0;

        // Generar archivos para tipo_cotizante = '16'
        if ($countTipo16 > 0) {
            $filesNeededTipo16 = max(
                (int) ceil($countTipo16 / self::MAX_ROWS_PER_SHEET),
                1
            );

            for ($fileIndex = 1; $fileIndex <= $filesNeededTipo16; $fileIndex++) {
                $this->generateExcelFile($run, $fileIndex, $filesNeededTipo16, '16');
                $totalFilesGenerated++;
            }

            Log::info('Archivos tipo_cotizante=16 generados', [
                'run_id' => $run->id,
                'registros' => $countTipo16,
                'archivos' => $filesNeededTipo16,
            ]);
        }

        // Generar archivos para tipo_cotizante != '16'
        if ($countOtros > 0) {
            $filesNeededOtros = max(
                (int) ceil($countOtros / self::MAX_ROWS_PER_SHEET),
                1
            );

            for ($fileIndex = 1; $fileIndex <= $filesNeededOtros; $fileIndex++) {
                $this->generateExcelFile($run, $fileIndex, $filesNeededOtros, 'otros');
                $totalFilesGenerated++;
            }

            Log::info('Archivos tipo_cotizante!=16 generados', [
                'run_id' => $run->id,
                'registros' => $countOtros,
                'archivos' => $filesNeededOtros,
            ]);
        }

        Log::info('Exportación DETTRA a Excel completada', [
            'run_id' => $run->id,
            'total_registros' => $totalRecords,
            'archivos_generados' => $totalFilesGenerated,
        ]);
    }

    /**
     * Cuenta registros de DETTRA por tipo_cotizante.
     *
     * @param CollectionNoticeRun $run
     * @param string $tipoCotizante '16' para tipo especial, 'otros' para el resto
     * @return int
     */
    private function countDettraRecordsByTipo(CollectionNoticeRun $run, string $tipoCotizante): int
    {
        if ($tipoCotizante === '16') {
            return (int) DB::selectOne("
                SELECT COUNT(*) as count
                FROM data_source_dettra
                WHERE run_id = ?
                    AND tipo_cotizante = '16'
            ", [$run->id])->count;
        }

        // Para 'otros', contar todos excepto tipo_cotizante = '16'
        return (int) DB::selectOne("
            SELECT COUNT(*) as count
            FROM data_source_dettra
            WHERE run_id = ?
                AND (tipo_cotizante IS NULL OR tipo_cotizante != '16')
        ", [$run->id])->count;
    }

    /**
     * Genera un archivo Excel.
     *
     * @param CollectionNoticeRun $run
     * @param int $fileIndex Índice del archivo (para paginación)
     * @param int $totalFiles Total de archivos a generar para este tipo
     * @param string $tipoCotizante '16' o 'otros'
     */
    private function generateExcelFile(
        CollectionNoticeRun $run,
        int $fileIndex,
        int $totalFiles,
        string $tipoCotizante
    ): void {
        $spreadsheet = new Spreadsheet();
        $this->generateSheet1($spreadsheet, $run, $fileIndex, $tipoCotizante);
        $this->generateSheet2($spreadsheet, $run, $fileIndex, $tipoCotizante);
        $this->saveExcelFile($spreadsheet, $run, $fileIndex, $totalFiles, $tipoCotizante);
    }

    /**
     * Genera Hoja 1 (Independientes) con data de DETTRA.
     *
     * @param Spreadsheet $spreadsheet
     * @param CollectionNoticeRun $run
     * @param int $fileIndex
     * @param string $tipoCotizante '16' o 'otros'
     */
    private function generateSheet1(Spreadsheet $spreadsheet, CollectionNoticeRun $run, int $fileIndex, string $tipoCotizante): void
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

        // Construir filtro WHERE según tipo_cotizante
        $whereClause = $tipoCotizante === '16'
            ? "d.run_id = ? AND d.tipo_cotizante = '16'"
            : "d.run_id = ? AND (d.tipo_cotizante IS NULL OR d.tipo_cotizante != '16')";

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
                d.nombre_ciudad as ciudad,
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
            WHERE {$whereClause}
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
     *
     * @param Spreadsheet $spreadsheet
     * @param CollectionNoticeRun $run
     * @param int $fileIndex
     * @param string $tipoCotizante '16' o 'otros'
     */
    private function generateSheet2(Spreadsheet $spreadsheet, CollectionNoticeRun $run, int $fileIndex, string $tipoCotizante): void
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

        // Construir filtro WHERE según tipo_cotizante
        $whereClause = $tipoCotizante === '16'
            ? "d.run_id = ? AND d.tipo_cotizante = '16'"
            : "d.run_id = ? AND (d.tipo_cotizante IS NULL OR d.tipo_cotizante != '16')";

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
            WHERE {$whereClause}
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
     *
     * @param Spreadsheet $spreadsheet
     * @param CollectionNoticeRun $run
     * @param int $fileIndex
     * @param int $totalFiles
     * @param string $tipoCotizante '16' o 'otros'
     * @return string
     */
    private function saveExcelFile(
        Spreadsheet $spreadsheet,
        CollectionNoticeRun $run,
        int $fileIndex,
        int $totalFiles,
        string $tipoCotizante
    ): string {
        // Nombre del archivo con periodo y tipo_cotizante
        if ($tipoCotizante === '16') {
            $baseName = sprintf('Constitucion_en_mora_independientes_tipo16_%s', $run->period);
        } else {
            $baseName = sprintf('Constitucion_en_mora_independientes_%s', $run->period);
        }

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

        // Contar registros en DETTRA para este archivo filtrando por tipo_cotizante
        $offsetCalc = ($fileIndex - 1) * self::MAX_ROWS_PER_SHEET;

        if ($tipoCotizante === '16') {
            $recordsCount = (int) DB::selectOne("
                SELECT COUNT(*) as count
                FROM data_source_dettra
                WHERE run_id = ?
                    AND tipo_cotizante = '16'
                LIMIT ? OFFSET ?
            ", [$run->id, self::MAX_ROWS_PER_SHEET, $offsetCalc])->count;
        } else {
            $recordsCount = (int) DB::selectOne("
                SELECT COUNT(*) as count
                FROM data_source_dettra
                WHERE run_id = ?
                    AND (tipo_cotizante IS NULL OR tipo_cotizante != '16')
                LIMIT ? OFFSET ?
            ", [$run->id, self::MAX_ROWS_PER_SHEET, $offsetCalc])->count;
        }

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
                'tipo_cotizante' => $tipoCotizante,
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
