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

        Log::info('Iniciando cruce BASCAR con PAGAPL optimizado', [
            'run_id' => $run->id,
            'period' => $period,
        ]);

        $startTime = microtime(true);

        // Contar totales antes del cruce
        $totalBascar = DB::table('data_source_bascar')
            ->where('run_id', $run->id)
            ->where('periodo', $period)
            ->count();

        // Estrategia optimizada: Crear tabla temporal con índice hash para cruce rápido
        DB::statement("
            CREATE TEMP TABLE IF NOT EXISTS temp_pagapl_keys_{$run->id} (
                composite_key VARCHAR(100) PRIMARY KEY
            ) ON COMMIT DROP
        ");

        // Insertar composite_keys de PAGAPL en tabla temporal (solo para este run)
        DB::statement("
            INSERT INTO temp_pagapl_keys_{$run->id} (composite_key)
            SELECT DISTINCT composite_key
            FROM data_source_pagapl
            WHERE run_id = ?
        ", [$run->id]);

        // Analizar tabla temporal para optimizar queries
        DB::statement("ANALYZE temp_pagapl_keys_{$run->id}");

        Log::info('Tabla temporal de composite_keys creada', [
            'run_id' => $run->id,
            'table' => "temp_pagapl_keys_{$run->id}",
        ]);

        // Contar coincidencias usando la tabla temporal (mucho más rápido)
        $coincidencias = (int) DB::selectOne("
            SELECT COUNT(*) as count
            FROM data_source_bascar b
            INNER JOIN temp_pagapl_keys_{$run->id} t
                ON b.composite_key = t.composite_key
            WHERE b.run_id = ?
                AND b.periodo = ?
        ", [$run->id, $period])->count;

        // Obtener tipo de comunicado (una sola vez, fuera del query pesado)
        $tipoComunicado = DB::table('collection_notice_types')
            ->where('id', $run->collection_notice_type_id)
            ->value('name');

        // Generar archivo CSV directamente desde la BD usando chunks y la tabla temporal
        $excludedFilePath = null;
        if ($coincidencias > 0) {
            $excludedFilePath = $this->generateExcludedFileFromDB($run, $period, $tipoComunicado, $coincidencias);
        }

        // Contar no coincidentes usando tabla temporal (LEFT JOIN con tabla pequeña)
        $nonMatchingCount = (int) DB::selectOne("
            SELECT COUNT(*) as count
            FROM data_source_bascar b
            LEFT JOIN temp_pagapl_keys_{$run->id} t
                ON b.composite_key = t.composite_key
            WHERE b.run_id = ?
                AND b.periodo = ?
                AND t.composite_key IS NULL
        ", [$run->id, $period])->count;

        // Limpiar tabla temporal
        DB::statement("DROP TABLE IF EXISTS temp_pagapl_keys_{$run->id}");

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        Log::info('Cruce completado optimizado', [
            'run_id' => $run->id,
            'total_bascar' => $totalBascar,
            'coincidencias' => $coincidencias,
            'no_coincidentes' => $nonMatchingCount,
            'duration_ms' => $duration,
        ]);

        // Actualizar contexto
        return $context
            ->addData('CROSS_BASCAR_PAGAPL', [
                'excluded_count' => $coincidencias,
                'non_matching_count' => $nonMatchingCount,
                'excluded_file_path' => $excludedFilePath,
                'in_database' => true,
            ])
            ->addStepResult($this->getName(), [
                'total_bascar_rows' => $totalBascar,
                'coincidences' => $coincidencias,
                'excluded' => $coincidencias,
                'non_matching' => $nonMatchingCount,
                'excluded_file' => $excludedFilePath,
                'duration_ms' => $duration,
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
     * Genera el archivo CSV de excluidos directamente desde la BD usando chunks.
     * Evita cargar todos los registros en memoria.
     *
     * @param \App\Models\CollectionNoticeRun $run
     * @param string $period
     * @param string $tipoComunicado
     * @param int $totalRecords
     *
     * @return string Ruta relativa del archivo generado
     */
    private function generateExcludedFileFromDB($run, string $period, string $tipoComunicado, int $totalRecords): string
    {
        $fileName = sprintf('excluidos%d.csv', $run->id);
        $relativeDir = sprintf('collection_notice_runs/%d/results', $run->id);
        $relativePath = $relativeDir . '/' . $fileName;

        $disk = $this->filesystem->disk('collection');

        // Crear directorio si no existe
        if (!$disk->exists($relativeDir)) {
            $disk->makeDirectory($relativeDir);
        }

        // Generar CSV en chunks directamente desde la BD
        $csvContent = "FECHA_CRUCE;NUMERO_ID_APORTANTE;PERIODO;TIPO_COMUNICADO;VALOR;MOTIVO_EXCLUSION\n";

        // Procesar en chunks de 5000 registros
        $chunkSize = 5000;
        $offset = 0;

        while ($offset < $totalRecords) {
            $rows = DB::select("
                SELECT
                    NOW() as fecha_cruce,
                    b.num_tomador as numero_id_aportante,
                    b.periodo,
                    ? as tipo_comunicado,
                    b.valor_total_fact as valor,
                    'Cruza con recaudo' as motivo_exclusion
                FROM data_source_bascar b
                INNER JOIN temp_pagapl_keys_{$run->id} t
                    ON b.composite_key = t.composite_key
                WHERE b.run_id = ?
                    AND b.periodo = ?
                ORDER BY b.id
                LIMIT ?
                OFFSET ?
            ", [$tipoComunicado, $run->id, $period, $chunkSize, $offset]);

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
            }

            $offset += $chunkSize;

            Log::debug('Chunk de excluidos procesado', [
                'run_id' => $run->id,
                'offset' => $offset,
                'total' => $totalRecords,
            ]);
        }

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
            'records_count' => $totalRecords,
            'metadata' => [
                'generated_at' => now()->toIso8601String(),
                'step' => 'cross_bascar_pagapl',
            ],
        ]);

        Log::info('Archivo de excluidos generado desde BD', [
            'run_id' => $run->id,
            'file_path' => $relativePath,
            'records_count' => $totalRecords,
            'size_bytes' => $fileSize,
        ]);

        return $relativePath;
    }

    /**
     * Genera el archivo CSV de excluidos.
     *
     * @param \App\Models\CollectionNoticeRun $run
     * @param array<int, array<string, string>> $excluidos
     *
     * @return string Ruta relativa del archivo generado
     * @deprecated Usar generateExcludedFileFromDB() que es más eficiente
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
