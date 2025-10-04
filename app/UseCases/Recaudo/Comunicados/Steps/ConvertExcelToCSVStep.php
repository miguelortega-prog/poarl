<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\DTOs\Recaudo\Comunicados\ProcessingContextDto;
use App\Services\Recaudo\GoExcelConverter;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Paso CRÍTICO: Convierte archivos Excel (DETTRA, PAGAPL, PAGPLA) a CSV usando Go streaming.
 *
 * Este es el paso más pesado del pipeline:
 * - Lee archivos Excel de 190MB+ sin cargar todo en memoria
 * - Procesa TODAS las hojas de TODOS los archivos xlsx
 * - Usa binario Go con streaming XML (8-10x más rápido que PHP)
 * - Genera CSVs con columna sheet_name para identificar origen
 *
 * Performance esperada:
 * - Archivo 190MB (2.6M filas): ~5 minutos
 * - Velocidad: ~8,500 filas/segundo
 */
final readonly class ConvertExcelToCSVStep implements ProcessingStepInterface
{
    /**
     * Códigos de data sources Excel que deben convertirse.
     */
    private const EXCEL_DATA_SOURCES = ['DETTRA', 'PAGAPL', 'PAGPLA'];

    public function __construct(
        private FilesystemFactory $filesystem,
        private GoExcelConverter $goConverter
    ) {
    }

    /**
     * @param ProcessingContextDto $context
     *
     * @return ProcessingContextDto
     */
    public function execute(ProcessingContextDto $context): ProcessingContextDto
    {
        $run = $context->run;
        $disk = $this->filesystem->disk('collection');

        Log::info('🚀 PASO CRÍTICO: Iniciando conversión Excel→CSV con Go streaming', [
            'step' => self::class,
            'run_id' => $run->id,
            'excel_files' => count(array_intersect(
                self::EXCEL_DATA_SOURCES,
                $run->files->pluck('dataSource.code')->toArray()
            )),
        ]);

        $stepStartTime = microtime(true);
        $totalFilesConverted = 0;
        $totalSheetsGenerated = 0;
        $totalRowsProcessed = 0;

        foreach ($run->files as $file) {
            $dataSourceCode = $file->dataSource->code ?? 'unknown';
            $extension = strtolower($file->ext ?? '');

            // Solo procesar archivos Excel de los data sources especificados
            if (!in_array($dataSourceCode, self::EXCEL_DATA_SOURCES, true)) {
                continue;
            }

            if ($extension !== 'xlsx') {
                Log::warning('Archivo Excel esperado pero encontrado con otra extensión', [
                    'data_source' => $dataSourceCode,
                    'extension' => $extension,
                    'file_path' => $file->path,
                ]);
                continue;
            }

            if (!$disk->exists($file->path)) {
                throw new RuntimeException(
                    sprintf('Archivo Excel no encontrado: %s', $file->path)
                );
            }

            $fileSize = $disk->size($file->path);
            $outputDir = sprintf('temp/csv_conversion/run_%d/%s', $run->id, $dataSourceCode);

            Log::info('📄 Convirtiendo Excel a CSV con Go streaming', [
                'run_id' => $run->id,
                'data_source' => $dataSourceCode,
                'file_path' => $file->path,
                'size_mb' => round($fileSize / 1024 / 1024, 2),
                'output_dir' => $outputDir,
            ]);

            $conversionStart = microtime(true);

            // Convertir Excel a CSVs usando Go streaming (sin cargar todo en memoria)
            $result = $this->goConverter->convertAllSheetsToSeparateCSVs(
                $disk,
                $file->path,
                $outputDir,
                ';' // Delimitador
            );

            $conversionDuration = (int) ((microtime(true) - $conversionStart) * 1000);

            $sheetsCount = count($result['sheets']);
            $totalFilesConverted++;
            $totalSheetsGenerated += $sheetsCount;

            // Calcular total de filas
            $sheetRows = array_sum(array_column($result['sheets'], 'rows'));
            $totalRowsProcessed += $sheetRows;

            Log::info('✅ Excel convertido exitosamente con Go', [
                'run_id' => $run->id,
                'data_source' => $dataSourceCode,
                'sheets_generated' => $sheetsCount,
                'total_rows' => number_format($sheetRows),
                'duration_ms' => $conversionDuration,
                'duration_sec' => round($conversionDuration / 1000, 2),
                'rows_per_second' => $conversionDuration > 0
                    ? round($sheetRows / ($conversionDuration / 1000))
                    : 0,
                'mb_per_second' => $conversionDuration > 0
                    ? round(($fileSize / 1024 / 1024) / ($conversionDuration / 1000), 2)
                    : 0,
            ]);

            // Agregar información al contexto para el siguiente paso
            $context = $context->addData($dataSourceCode, [
                'excel_path' => $file->path,
                'csv_output_dir' => $outputDir,
                'sheets' => $result['sheets'],
                'conversion_duration_ms' => $conversionDuration,
                'total_rows' => $sheetRows,
            ]);
        }

        $stepDuration = (int) ((microtime(true) - $stepStartTime) * 1000);

        Log::info('🎉 Conversión Excel→CSV completada (Go streaming)', [
            'step' => self::class,
            'run_id' => $run->id,
            'files_converted' => $totalFilesConverted,
            'sheets_generated' => $totalSheetsGenerated,
            'total_rows' => number_format($totalRowsProcessed),
            'total_duration_ms' => $stepDuration,
            'total_duration_sec' => round($stepDuration / 1000, 2),
            'avg_rows_per_second' => $stepDuration > 0
                ? round($totalRowsProcessed / ($stepDuration / 1000))
                : 0,
        ]);

        return $context->addStepResult($this->getName(), [
            'files_converted' => $totalFilesConverted,
            'sheets_generated' => $totalSheetsGenerated,
            'total_rows' => $totalRowsProcessed,
            'duration_ms' => $stepDuration,
        ]);
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'Convertir Excel a CSV (Go Streaming)';
    }

    /**
     * @param ProcessingContextDto $context
     *
     * @return bool
     */
    public function shouldExecute(ProcessingContextDto $context): bool
    {
        // Solo ejecutar si hay archivos Excel que convertir
        $excelFiles = $context->run->files->filter(function ($file) {
            $dataSourceCode = $file->dataSource->code ?? '';
            $extension = strtolower($file->ext ?? '');

            return in_array($dataSourceCode, self::EXCEL_DATA_SOURCES, true)
                && $extension === 'xlsx';
        });

        return $excelFiles->isNotEmpty();
    }
}
