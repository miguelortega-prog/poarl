<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\DTOs\Recaudo\Comunicados\ProcessingContextDto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Paso para generar llave compuesta en BASCAR usando SQL.
 *
 * Actualiza el campo 'composite_key' concatenando NUM_TOMADOR + periodo
 * directamente en la base de datos para máxima eficiencia.
 */
final readonly class GenerateBascarCompositeKeyStep implements ProcessingStepInterface
{
    private const BASCAR_CODE = 'BASCAR';

    /**
     * @param ProcessingContextDto $context
     *
     * @return ProcessingContextDto
     */
    public function execute(ProcessingContextDto $context): ProcessingContextDto
    {
        $run = $context->run;
        $period = $run->period;

        Log::info('Generando llaves compuestas en BASCAR con SQL', [
            'run_id' => $run->id,
            'period' => $period,
        ]);

        // Generar composite_key = num_tomador || periodo usando SQL
        DB::statement("
            UPDATE data_source_bascar
            SET composite_key = TRIM(num_tomador) || periodo
            WHERE run_id = ?
                AND composite_key IS NULL
                AND num_tomador IS NOT NULL
                AND periodo IS NOT NULL
        ", [$run->id]);

        // Contar resultados
        $totalRows = DB::table('data_source_bascar')
            ->where('run_id', $run->id)
            ->count();

        $keysGenerated = DB::table('data_source_bascar')
            ->where('run_id', $run->id)
            ->whereNotNull('composite_key')
            ->count();

        $missingNumTomador = DB::table('data_source_bascar')
            ->where('run_id', $run->id)
            ->whereNull('num_tomador')
            ->count();

        if ($missingNumTomador > 0) {
            Log::warning('Algunas filas no tienen NUM_TOMADOR', [
                'run_id' => $run->id,
                'missing_num_tomador' => $missingNumTomador,
            ]);
        }

        Log::info('Llaves compuestas generadas en BASCAR', [
            'run_id' => $run->id,
            'total_rows' => $totalRows,
            'keys_generated' => $keysGenerated,
            'missing_num_tomador' => $missingNumTomador,
        ]);

        $bascarData = $context->getData(self::BASCAR_CODE);

        return $context->addData(self::BASCAR_CODE, [
            ...$bascarData,
            'composite_keys_generated' => true,
            'keys_count' => $keysGenerated,
        ])->addStepResult($this->getName(), [
            'total_rows' => $totalRows,
            'keys_generated' => $keysGenerated,
            'missing_num_tomador' => $missingNumTomador,
        ]);
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'Generar llaves compuestas en BASCAR';
    }

    /**
     * @param ProcessingContextDto $context
     *
     * @return bool
     */
    public function shouldExecute(ProcessingContextDto $context): bool
    {
        $bascarData = $context->getData(self::BASCAR_CODE);

        // Solo ejecutar si BASCAR existe, está en BD y tiene filas
        return $bascarData !== null &&
               ($bascarData['loaded_to_db'] ?? false) &&
               ($bascarData['matched_rows'] ?? 0) > 0;
    }
}
