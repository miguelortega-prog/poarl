<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Limpiar datos de tablas data_source_ del run.
 *
 * Elimina todos los registros de las tablas data_source_ asociados al run actual
 * para liberar espacio en base de datos después de que el procesamiento ha completado exitosamente.
 *
 * IMPORTANTE: Este step debe ejecutarse DESPUÉS de que todos los archivos de salida
 * hayan sido generados, ya que los datos no podrán recuperarse una vez eliminados.
 */
final class CleanupDataSourcesStep implements ProcessingStepInterface
{
    /**
     * Tablas de data sources a limpiar.
     */
    private const DATA_SOURCE_TABLES = [
        'data_source_bascar',
        'data_source_pagapl',
        'data_source_baprpo',
        'data_source_pagpla',
        'data_source_datpol',
        'data_source_dettra',
        'data_source_basact',
        'data_source_paglog',
    ];

    public function getName(): string
    {
        return 'Limpiar datos de data sources';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Limpiando datos de data sources', ['run_id' => $run->id]);

        foreach (self::DATA_SOURCE_TABLES as $table) {
            $this->cleanupTable($table, $run->id);
        }

        Log::info('Datos de data sources eliminados', ['run_id' => $run->id]);
    }

    /**
     * Elimina registros de una tabla específica.
     */
    private function cleanupTable(string $table, int $runId): void
    {
        DB::delete("DELETE FROM {$table} WHERE run_id = ?", [$runId]);
    }
}
