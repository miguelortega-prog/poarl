<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\DTOs\Recaudo\Comunicados\ProcessingContextDto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Paso para generar llave compuesta en PAGAPL usando SQL.
 *
 * Actualiza el campo 'composite_key' concatenando identificacion + periodo
 * directamente en la base de datos para máxima eficiencia.
 */
final readonly class GeneratePagaplCompositeKeyStep implements ProcessingStepInterface
{
    private const PAGAPL_CODE = 'PAGAPL';

    /**
     * @param ProcessingContextDto $context
     *
     * @return ProcessingContextDto
     */
    public function execute(ProcessingContextDto $context): ProcessingContextDto
    {
        $run = $context->run;

        Log::info('Generando llaves compuestas en PAGAPL con SQL', [
            'run_id' => $run->id,
        ]);

        // Generar composite_key = identificacion || periodo usando SQL
        DB::statement("
            UPDATE data_source_pagapl
            SET composite_key = TRIM(identificacion) || periodo
            WHERE run_id = ?
                AND composite_key IS NULL
                AND identificacion IS NOT NULL
                AND periodo IS NOT NULL
        ", [$run->id]);

        // Contar resultados
        $totalRows = DB::table('data_source_pagapl')
            ->where('run_id', $run->id)
            ->count();

        $keysGenerated = DB::table('data_source_pagapl')
            ->where('run_id', $run->id)
            ->whereNotNull('composite_key')
            ->count();

        $missingIdentificacion = DB::table('data_source_pagapl')
            ->where('run_id', $run->id)
            ->whereNull('identificacion')
            ->count();

        if ($missingIdentificacion > 0) {
            Log::warning('Algunas filas no tienen Identificación', [
                'run_id' => $run->id,
                'missing_identificacion' => $missingIdentificacion,
            ]);
        }

        Log::info('Llaves compuestas generadas en PAGAPL', [
            'run_id' => $run->id,
            'total_rows' => $totalRows,
            'keys_generated' => $keysGenerated,
            'missing_identificacion' => $missingIdentificacion,
        ]);

        $pagaplData = $context->getData(self::PAGAPL_CODE);

        return $context->addData(self::PAGAPL_CODE, [
            ...$pagaplData,
            'composite_keys_generated' => true,
            'keys_count' => $keysGenerated,
        ])->addStepResult($this->getName(), [
            'total_rows' => $totalRows,
            'keys_generated' => $keysGenerated,
            'missing_identificacion' => $missingIdentificacion,
        ]);
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'Generar llaves compuestas en PAGAPL';
    }

    /**
     * @param ProcessingContextDto $context
     *
     * @return bool
     */
    public function shouldExecute(ProcessingContextDto $context): bool
    {
        $pagaplData = $context->getData(self::PAGAPL_CODE);

        // Solo ejecutar si PAGAPL existe y está cargado en BD
        return $pagaplData !== null && ($pagaplData['loaded_to_db'] ?? false);
    }
}
