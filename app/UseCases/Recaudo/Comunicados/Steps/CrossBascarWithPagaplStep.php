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
 * Step: Cruzar BASCAR con PAGAPL para identificar aportantes que ya pagaron.
 *
 * Realiza INNER JOIN directo entre las tablas usando composite_key.
 * Los registros que coinciden (aportantes que pagaron) se guardan en excluidos{run_id}.csv
 *
 * Lógica:
 * - BASCAR = Base de cartera (aportantes con deuda)
 * - PAGAPL = Pagos aplicados
 * - Si BASCAR.composite_key existe en PAGAPL → el aportante ya pagó → EXCLUIR
 *
 * Output: excluidos{run_id}.csv con aportantes que NO deben recibir comunicado
 */
final class CrossBascarWithPagaplStep implements ProcessingStepInterface
{
    public function __construct(
        private readonly FilesystemFactory $filesystem
    ) {
    }

    public function getName(): string
    {
        return 'Cruzar BASCAR con PAGAPL';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        $startTime = microtime(true);

        Log::info('🔄 Cruzando BASCAR con PAGAPL', [
            'step' => self::class,
            'run_id' => $run->id,
            'period' => $run->period,
        ]);

        // Contar totales antes del cruce
        $totalBascar = DB::table('data_source_bascar')
            ->where('run_id', $run->id)
            ->count();

        Log::info('Registros en BASCAR antes del cruce', [
            'run_id' => $run->id,
            'total_bascar' => $totalBascar,
        ]);

        // Contar coincidencias con JOIN directo (PostgreSQL optimizará con los índices)
        $coincidencias = (int) DB::selectOne("
            SELECT COUNT(DISTINCT b.id) as count
            FROM data_source_bascar b
            INNER JOIN data_source_pagapl p
                ON b.composite_key = p.composite_key
            WHERE b.run_id = ?
                AND p.run_id = ?
        ", [$run->id, $run->id])->count;

        Log::info('Coincidencias encontradas', [
            'run_id' => $run->id,
            'coincidencias' => $coincidencias,
            'porcentaje' => $totalBascar > 0 ? round(($coincidencias / $totalBascar) * 100, 2) : 0,
        ]);

        // Generar archivo CSV de excluidos si hay coincidencias
        $excludedFilePath = null;
        if ($coincidencias > 0) {
            $excludedFilePath = $this->generateExcludedFileFromDB($run, $coincidencias);
        } else {
            Log::info('No hay coincidencias, no se genera archivo de excluidos', [
                'run_id' => $run->id,
            ]);
        }

        // Contar registros que NO coinciden (se procesarán en pasos siguientes)
        $nonMatchingCount = (int) DB::selectOne("
            SELECT COUNT(*) as count
            FROM data_source_bascar b
            WHERE b.run_id = ?
                AND NOT EXISTS (
                    SELECT 1
                    FROM data_source_pagapl p
                    WHERE p.composite_key = b.composite_key
                        AND p.run_id = ?
                )
        ", [$run->id, $run->id])->count;

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('✅ Cruce BASCAR-PAGAPL completado', [
            'run_id' => $run->id,
            'total_bascar' => $totalBascar,
            'coincidencias' => $coincidencias,
            'no_coincidentes' => $nonMatchingCount,
            'excluded_file' => $excludedFilePath,
            'duration_ms' => $duration,
        ]);

        // Validar consistencia
        if ($coincidencias + $nonMatchingCount !== $totalBascar) {
            Log::warning('⚠️  Inconsistencia en conteo de cruce', [
                'run_id' => $run->id,
                'total_bascar' => $totalBascar,
                'coincidencias' => $coincidencias,
                'no_coincidentes' => $nonMatchingCount,
                'suma' => $coincidencias + $nonMatchingCount,
            ]);
        }
    }


    /**
     * Genera el archivo CSV de excluidos directamente desde la BD usando chunks.
     * Evita cargar todos los registros en memoria.
     */
    private function generateExcludedFileFromDB(CollectionNoticeRun $run, int $totalRecords): string
    {
        $fileName = sprintf('excluidos%d.csv', $run->id);
        $relativeDir = sprintf('collection_notice_runs/%d/results', $run->id);
        $relativePath = $relativeDir . '/' . $fileName;

        $disk = $this->filesystem->disk('collection');

        // Crear directorio si no existe
        if (!$disk->exists($relativeDir)) {
            $disk->makeDirectory($relativeDir);
        }

        Log::info('Generando archivo de excluidos', [
            'run_id' => $run->id,
            'total_records' => $totalRecords,
            'file' => $fileName,
        ]);

        // Obtener tipo de comunicado
        $tipoComunicado = $run->type?->name ?? 'Sin tipo';

        // Generar CSV en chunks directamente desde la BD
        $csvContent = "FECHA_CRUCE;NUMERO_ID_APORTANTE;PERIODO;TIPO_COMUNICADO;VALOR;MOTIVO_EXCLUSION\n";

        // Procesar en chunks de 5000 registros
        $chunkSize = 5000;
        $offset = 0;
        $processedRows = 0;

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
                INNER JOIN data_source_pagapl p
                    ON b.composite_key = p.composite_key
                WHERE b.run_id = ?
                    AND p.run_id = ?
                ORDER BY b.id
                LIMIT ?
                OFFSET ?
            ", [$tipoComunicado, $run->id, $run->id, $chunkSize, $offset]);

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

            if ($offset % 10000 === 0) {
                Log::debug('Progreso generación de excluidos', [
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
            'file_type' => 'excluidos',
            'file_name' => $fileName,
            'disk' => 'collection',
            'path' => $relativePath,
            'size' => $fileSize,
            'records_count' => $processedRows,
            'metadata' => [
                'generated_at' => now()->toIso8601String(),
                'step' => 'cross_bascar_pagapl',
                'tipo_comunicado' => $tipoComunicado,
            ],
        ]);

        Log::info('✅ Archivo de excluidos generado', [
            'run_id' => $run->id,
            'file_path' => $relativePath,
            'records_count' => $processedRows,
            'size_kb' => round($fileSize / 1024, 2),
        ]);

        return $relativePath;
    }
}
