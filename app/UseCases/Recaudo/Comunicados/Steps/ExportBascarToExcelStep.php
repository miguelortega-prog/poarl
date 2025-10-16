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
 * Step: Exportar BASCAR a Excel 97 (.xls).
 *
 * Genera archivos Excel 97 (.xls) con 2 hojas:
 *
 * Hoja 1 (Empresas): Data de BASCAR con campos específicos
 * Hoja 2 (Expuestos): Data de detalle_trabajadores con columna TIPO DE ENVIO
 *                     (CSV generado en CrearBaseTrabajadoresActivosStep + cruce con BASCAR)
 *
 * Límite Excel 97: 65,536 filas por hoja (65,535 datos + 1 encabezado)
 * Si se supera el límite, crea archivos adicionales (_parte2, _parte3, etc.)
 *
 * Nombre: Constitucion_en_mora_periodo_cotización_{{periodo}}.xls
 */
final class ExportBascarToExcelStep implements ProcessingStepInterface
{
    private const MAX_ROWS_PER_SHEET = 65535; // 65,536 - 1 header

    public function __construct(
        private readonly FilesystemFactory $filesystem
    ) {
    }

    public function getName(): string
    {
        return 'Exportar BASCAR a Excel';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Exportando BASCAR a Excel', ['run_id' => $run->id]);

        $bascarCount = $this->countBascarRecords($run);
        $detalleCount = $this->countDetalleRecords($run);

        $filesNeeded = max(
            (int) ceil($bascarCount / self::MAX_ROWS_PER_SHEET),
            (int) ceil($detalleCount / self::MAX_ROWS_PER_SHEET),
            1
        );

        for ($fileIndex = 1; $fileIndex <= $filesNeeded; $fileIndex++) {
            $this->generateExcelFile($run, $fileIndex, $filesNeeded, $bascarCount, $detalleCount);
        }

        Log::info('Exportación BASCAR a Excel completada', ['run_id' => $run->id]);
    }

    /**
     * Cuenta registros de BASCAR.
     */
    private function countBascarRecords(CollectionNoticeRun $run): int
    {
        return (int) DB::selectOne("
            SELECT COUNT(*) as count
            FROM data_source_bascar
            WHERE run_id = ?
        ", [$run->id])->count;
    }

    /**
     * Cuenta registros de detalle_trabajadores.
     */
    private function countDetalleRecords(CollectionNoticeRun $run): int
    {
        $resultFile = CollectionNoticeRunResultFile::where('collection_notice_run_id', $run->id)
            ->where('file_type', 'detalle_trabajadores')
            ->first();

        return $resultFile?->records_count ?? 0;
    }

    /**
     * Genera un archivo Excel.
     */
    private function generateExcelFile(
        CollectionNoticeRun $run,
        int $fileIndex,
        int $totalFiles,
        int $bascarCount,
        int $detalleCount
    ): void {
        $spreadsheet = new Spreadsheet();
        $this->generateSheet1($spreadsheet, $run, $fileIndex);
        $this->generateSheet2($spreadsheet, $run, $fileIndex);
        $this->saveExcelFile($spreadsheet, $run, $fileIndex, $totalFiles);
    }

    /**
     * Genera Hoja 1 (Empresas) con data de BASCAR.
     */
    private function generateSheet1(Spreadsheet $spreadsheet, CollectionNoticeRun $run, int $fileIndex): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Empresas');

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
            'TIP IND',
            'COD CIUDAD',
        ];

        // Escribir encabezados
        $sheet->fromArray($headers, null, 'A1');

        // Calcular offset y limit
        $offset = ($fileIndex - 1) * self::MAX_ROWS_PER_SHEET;
        $limit = self::MAX_ROWS_PER_SHEET;

        // Obtener data de BASCAR
        // NOTA: valor_total_fact ya fue sanitizado y redondeado por SanitizeNumericFieldsStep
        $bascarData = DB::select("
            SELECT
                b.num_tomador as nit,
                CONCAT('COPASST ', COALESCE(b.nom_tomador, '')) as representante_legal,
                b.email as correo,
                COALESCE(r.official_id, '') as cedula,
                CONCAT('COPASST ', COALESCE(b.nom_tomador, '')) as nombre_empresa,
                'COPASST' as cargo,
                b.direccion,
                b.ciu_tom as ciudad,
                b.num_poliza as contrato,
                SUBSTRING(b.periodo FROM 1 FOR 4) as anio1,
                SUBSTRING(b.periodo FROM 5 FOR 2) as mes1,
                b.valor_total_fact as valor1,
                b.cantidad_trabajadores as afiliados1,
                b.consecutivo,
                b.ident_asegurado as tip_ind,
                b.divipola as cod_ciudad
            FROM data_source_bascar b
            INNER JOIN collection_notice_runs r ON b.run_id = r.id
            WHERE b.run_id = ?
            ORDER BY b.id
            LIMIT ? OFFSET ?
        ", [$run->id, $limit, $offset]);

        // Escribir datos
        $row = 2; // Empezar en fila 2 (después del encabezado)
        foreach ($bascarData as $data) {
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
                $data->tip_ind,
                $data->cod_ciudad,
            ], null, 'A' . $row);
            $row++;
        }
    }

    /**
     * Genera Hoja 2 (Expuestos) con data de detalle_trabajadores + TIPO DE ENVIO.
     *
     * Agrega columna TIPO DE ENVIO al final, cruzando NRO_IDVI (columna 6 del CSV, índice 5)
     * con num_tomador de data_source_bascar para obtener tipo_de_envio.
     */
    private function generateSheet2(Spreadsheet $spreadsheet, CollectionNoticeRun $run, int $fileIndex): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Expuestos');

        // Buscar archivo CSV de detalle_trabajadores
        $resultFile = CollectionNoticeRunResultFile::where('collection_notice_run_id', $run->id)
            ->where('file_type', 'detalle_trabajadores')
            ->first();

        if (!$resultFile) {
            return;
        }

        $disk = $this->filesystem->disk($resultFile->disk);

        if (!$disk->exists($resultFile->path)) {
            return;
        }

        $tipoEnvioMap = $this->loadTipoEnvioMap($run);

        // Calcular líneas a saltar y leer
        $skipLines = ($fileIndex - 1) * self::MAX_ROWS_PER_SHEET;
        $maxLines = self::MAX_ROWS_PER_SHEET + 1; // +1 para el header

        // Leer CSV
        $csvPath = $disk->path($resultFile->path);
        $csvFile = fopen($csvPath, 'r');

        $currentLine = 0;
        $excelRow = 1;
        $writtenRows = 0;

        while (($data = fgetcsv($csvFile, 0, ';')) !== false) {
            // Escribir header siempre (línea 0)
            if ($currentLine === 0) {
                // Agregar columna "TIPO DE ENVIO" al final del header
                $data[] = 'TIPO DE ENVIO';
                $sheet->fromArray($data, null, 'A' . $excelRow);
                $excelRow++;
                $currentLine++;
                continue;
            }

            // Saltar líneas si es archivo posterior al primero
            if ($currentLine <= $skipLines) {
                $currentLine++;
                continue;
            }

            // Agregar columna TIPO DE ENVIO al final
            // NRO_IDVI está en el índice 5 del CSV (columna 6)
            // 0:TPO_IDEN_TRABAJADOR, 1:NRO_IDEN, 2:AÑO, 3:MES, 4:TPO_EMP, 5:NRO_IDVI
            $nroIdvi = $data[5] ?? '';
            $tipoEnvio = $tipoEnvioMap[$nroIdvi] ?? '';
            $data[] = $tipoEnvio;

            // Escribir datos
            $sheet->fromArray($data, null, 'A' . $excelRow);
            $excelRow++;
            $writtenRows++;
            $currentLine++;

            // Si alcanzamos el límite, detener
            if ($writtenRows >= self::MAX_ROWS_PER_SHEET) {
                break;
            }
        }

        fclose($csvFile);
    }

    /**
     * Carga mapa de num_tomador → tipo_de_envio de BASCAR.
     *
     * @return array<string, string> Key: num_tomador, Value: tipo_de_envio
     */
    private function loadTipoEnvioMap(CollectionNoticeRun $run): array
    {
        $results = DB::select("
            SELECT
                num_tomador,
                COALESCE(tipo_de_envio, '') as tipo_de_envio
            FROM data_source_bascar
            WHERE run_id = ?
                AND num_tomador IS NOT NULL
        ", [$run->id]);

        $map = [];
        foreach ($results as $row) {
            $map[$row->num_tomador] = $row->tipo_de_envio;
        }

        return $map;
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
        $baseName = sprintf('Constitucion_en_mora_periodo_cotización_%s', $run->period);

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

        // Contar registros en BASCAR para este archivo
        $offset = ($fileIndex - 1) * self::MAX_ROWS_PER_SHEET;
        $recordsCount = (int) DB::selectOne("
            SELECT COUNT(*) as count
            FROM data_source_bascar
            WHERE run_id = ?
            LIMIT ? OFFSET ?
        ", [$run->id, self::MAX_ROWS_PER_SHEET, $offset])->count;

        // Registrar en BD
        CollectionNoticeRunResultFile::create([
            'collection_notice_run_id' => $run->id,
            'file_type' => 'comunicado_excel',
            'file_name' => $fileName,
            'disk' => 'collection',
            'path' => $relativePath,
            'size' => $fileSize,
            'records_count' => $recordsCount,
            'metadata' => [
                'generated_at' => now()->toIso8601String(),
                'step' => 'export_bascar_to_excel',
                'format' => 'xls',
                'sheets' => 2,
                'file_index' => $fileIndex,
                'total_files' => $totalFiles,
                'periodo' => $run->period,
            ],
        ]);

        return $relativePath;
    }
}
