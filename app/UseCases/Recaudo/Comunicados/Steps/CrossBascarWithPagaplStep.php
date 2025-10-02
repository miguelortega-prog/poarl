<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\DTOs\Recaudo\Comunicados\ProcessingContextDto;
use App\Models\CollectionNoticeRunResultFile;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Paso para cruzar BASCAR con PAGAPL usando SQL JOIN y generar archivo de excluidos.
 *
 * Realiza el cruce directamente en PostgreSQL con INNER JOIN sobre composite_key.
 * Los registros coincidentes se guardan en excluidos{#run}.csv
 * Los registros no coincidentes quedan disponibles para siguientes pasos.
 */
final readonly class CrossBascarWithPagaplStep implements ProcessingStepInterface
{
    private const BASCAR_CODE = 'BASCAR';
    private const PAGAPL_CODE = 'PAGAPL';

    public function __construct(
        private FilesystemFactory $filesystem
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
        $period = $run->period;

        Log::info('Iniciando cruce BASCAR con PAGAPL con SQL JOIN', [
            'run_id' => $run->id,
            'period' => $period,
        ]);

        // Contar totales antes del cruce
        $totalBascar = DB::table('data_source_bascar')
            ->where('run_id', $run->id)
            ->where('periodo', $period)
            ->count();

        // Ejecutar cruce con INNER JOIN para obtener excluidos
        $excluidos = DB::select("
            SELECT
                NOW() as fecha_cruce,
                b.num_tomador as numero_id_aportante,
                b.periodo,
                t.name as tipo_comunicado,
                b.valor_total_fact as valor,
                'Cruza con recaudo' as motivo_exclusion
            FROM data_source_bascar b
            INNER JOIN data_source_pagapl p
                ON b.run_id = p.run_id
                AND b.composite_key = p.composite_key
            INNER JOIN collection_notice_types t
                ON t.id = ?
            WHERE b.run_id = ?
                AND b.periodo = ?
        ", [$run->collection_notice_type_id, $run->id, $period]);

        $coincidencias = count($excluidos);

        // Obtener IDs de BASCAR que NO coincidieron (para siguientes pasos)
        $nonMatchingCount = DB::selectOne("
            SELECT COUNT(*) as count
            FROM data_source_bascar b
            LEFT JOIN data_source_pagapl p
                ON b.run_id = p.run_id
                AND b.composite_key = p.composite_key
            WHERE b.run_id = ?
                AND b.periodo = ?
                AND p.id IS NULL
        ", [$run->id, $period])->count;

        Log::info('Cruce completado con SQL', [
            'run_id' => $run->id,
            'total_bascar' => $totalBascar,
            'coincidencias' => $coincidencias,
            'no_coincidentes' => $nonMatchingCount,
        ]);

        // Generar archivo CSV de excluidos
        $excludedFilePath = null;
        if ($coincidencias > 0) {
            // Convertir stdClass a array asociativo para generateExcludedFile
            $excluidos = array_map(fn($obj) => (array) $obj, $excluidos);
            $excludedFilePath = $this->generateExcludedFile($run, $excluidos);
        }

        // Actualizar contexto
        return $context
            ->addData('CROSS_BASCAR_PAGAPL', [
                'excluded_count' => $coincidencias,
                'non_matching_count' => $nonMatchingCount,
                'excluded_file_path' => $excludedFilePath,
                'in_database' => true, // Los datos están en BD, no en memoria
            ])
            ->addStepResult($this->getName(), [
                'total_bascar_rows' => $totalBascar,
                'coincidences' => $coincidencias,
                'excluded' => $coincidencias,
                'non_matching' => $nonMatchingCount,
                'excluded_file' => $excludedFilePath,
            ]);
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'Cruzar BASCAR con PAGAPL';
    }

    /**
     * @param ProcessingContextDto $context
     *
     * @return bool
     */
    public function shouldExecute(ProcessingContextDto $context): bool
    {
        $bascarData = $context->getData(self::BASCAR_CODE);
        $pagaplData = $context->getData(self::PAGAPL_CODE);

        // Solo ejecutar si ambos están cargados en BD y tienen composite keys generadas
        return $bascarData !== null &&
               $pagaplData !== null &&
               ($bascarData['loaded_to_db'] ?? false) &&
               ($pagaplData['loaded_to_db'] ?? false) &&
               ($bascarData['composite_keys_generated'] ?? false) &&
               ($pagaplData['composite_keys_generated'] ?? false);
    }


    /**
     * Genera el archivo CSV de excluidos.
     *
     * @param \App\Models\CollectionNoticeRun $run
     * @param array<int, array<string, string>> $excluidos
     *
     * @return string Ruta relativa del archivo generado
     */
    private function generateExcludedFile($run, array $excluidos): string
    {
        $fileName = sprintf('excluidos%d.csv', $run->id);
        $relativeDir = sprintf('collection_notice_runs/%d/results', $run->id);
        $relativePath = $relativeDir . '/' . $fileName;

        $disk = $this->filesystem->disk('collection');

        // Crear directorio si no existe
        if (!$disk->exists($relativeDir)) {
            $disk->makeDirectory($relativeDir);
        }

        // Generar contenido CSV
        $csvContent = $this->generateCsvContent($excluidos);

        // Guardar archivo
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
            'records_count' => count($excluidos),
            'metadata' => [
                'generated_at' => now()->toIso8601String(),
                'step' => 'cross_bascar_pagapl',
            ],
        ]);

        Log::info('Archivo de excluidos generado', [
            'run_id' => $run->id,
            'file_path' => $relativePath,
            'records_count' => count($excluidos),
            'size_bytes' => $fileSize,
        ]);

        return $relativePath;
    }

    /**
     * Genera el contenido CSV con separador punto y coma.
     *
     * @param array<int, array<string, string>> $records
     *
     * @return string
     */
    private function generateCsvContent(array $records): string
    {
        if ($records === []) {
            return '';
        }

        $output = '';

        // Encabezados en mayúsculas
        $headers = array_keys($records[0]);
        $output .= implode(';', array_map('strtoupper', $headers)) . "\n";

        // Datos
        foreach ($records as $record) {
            $output .= implode(';', array_values($record)) . "\n";
        }

        return $output;
    }
}
