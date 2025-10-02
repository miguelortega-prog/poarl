<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\DTOs\Recaudo\Comunicados\ProcessingContextDto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Paso para calcular periodo y filtrar BASCAR en base de datos.
 *
 * Calcula el campo 'periodo' (YYYYMM) desde FECHA_INICIO_VIG usando SQL.
 * Los registros ya están filtrados por run_id, solo necesitamos calcular el periodo.
 *
 * Si el periodo es "Todos los periodos" (*), no filtra por periodo.
 */
final readonly class FilterBascarByPeriodStep implements ProcessingStepInterface
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

        Log::info('Calculando periodo en BASCAR con SQL', [
            'run_id' => $run->id,
            'period' => $period,
        ]);

        // Calcular periodo desde FECHA_INICIO_VIG usando SQL
        // Soporta formatos: DD/MM/YYYY, D/MM/YYYY, YYYY-MM-DD
        DB::statement("
            UPDATE data_source_bascar
            SET periodo = CASE
                -- Formato DD/MM/YYYY o D/MM/YYYY (12/08/2025)
                WHEN fecha_inicio_vig ~ '^[0-9]{1,2}/[0-9]{1,2}/[0-9]{4}$' THEN
                    SUBSTRING(fecha_inicio_vig FROM '([0-9]{4})$') ||
                    LPAD(SUBSTRING(fecha_inicio_vig FROM '^[0-9]{1,2}/([0-9]{1,2})/'), 2, '0')
                -- Formato YYYY-MM-DD (2025-08-12)
                WHEN fecha_inicio_vig ~ '^[0-9]{4}-[0-9]{1,2}-[0-9]{1,2}$' THEN
                    SUBSTRING(fecha_inicio_vig FROM '^([0-9]{4})') ||
                    LPAD(SUBSTRING(fecha_inicio_vig FROM '^[0-9]{4}-([0-9]{1,2})-'), 2, '0')
                -- Formato YYYYMMDD (20250812)
                WHEN fecha_inicio_vig ~ '^[0-9]{8}$' THEN
                    SUBSTRING(fecha_inicio_vig FROM 1 FOR 6)
                ELSE NULL
            END
            WHERE run_id = ?
                AND periodo IS NULL
        ", [$run->id]);

        // Contar registros totales y con periodo calculado
        $totalRows = DB::table('data_source_bascar')
            ->where('run_id', $run->id)
            ->count();

        $rowsWithPeriod = DB::table('data_source_bascar')
            ->where('run_id', $run->id)
            ->whereNotNull('periodo')
            ->count();

        $rowsWithoutPeriod = $totalRows - $rowsWithPeriod;

        if ($rowsWithoutPeriod > 0) {
            Log::warning('Algunas filas no tienen periodo calculado', [
                'run_id' => $run->id,
                'rows_without_period' => $rowsWithoutPeriod,
            ]);
        }

        // Si el periodo es "*", no filtrar
        if ($period === '*') {
            Log::info('Periodo es "Todos los periodos", no se filtra', [
                'run_id' => $run->id,
                'total_rows' => $totalRows,
            ]);

            $bascarData = $context->getData(self::BASCAR_CODE);

            return $context->addData(self::BASCAR_CODE, [
                ...$bascarData,
                'filtered' => false,
                'total_rows' => $totalRows,
                'matched_rows' => $totalRows,
            ])->addStepResult($this->getName(), [
                'total_rows' => $totalRows,
                'matched_rows' => $totalRows,
                'period' => '*',
            ]);
        }

        // Contar cuántos registros coinciden con el periodo
        $matchedRows = DB::table('data_source_bascar')
            ->where('run_id', $run->id)
            ->where('periodo', $period)
            ->count();

        Log::info('BASCAR filtrado por periodo (en BD)', [
            'run_id' => $run->id,
            'period' => $period,
            'total_rows' => $totalRows,
            'matched_rows' => $matchedRows,
            'filtered_percentage' => $totalRows > 0 ? round(($matchedRows / $totalRows) * 100, 2) : 0,
        ]);

        $bascarData = $context->getData(self::BASCAR_CODE);

        return $context->addData(self::BASCAR_CODE, [
            ...$bascarData,
            'filtered' => true,
            'in_database' => true,
            'total_rows' => $totalRows,
            'matched_rows' => $matchedRows,
        ])->addStepResult($this->getName(), [
            'total_rows' => $totalRows,
            'matched_rows' => $matchedRows,
            'period' => $period,
        ]);
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'Filtrar BASCAR por periodo';
    }

    /**
     * @param ProcessingContextDto $context
     *
     * @return bool
     */
    public function shouldExecute(ProcessingContextDto $context): bool
    {
        // Solo ejecutar si existe BASCAR en el contexto y está cargado en BD
        $bascarData = $context->getData(self::BASCAR_CODE);

        return $bascarData !== null && ($bascarData['loaded_to_db'] ?? false);
    }
}
