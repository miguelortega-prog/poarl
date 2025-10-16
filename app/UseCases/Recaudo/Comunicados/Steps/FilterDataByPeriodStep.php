<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Filtrar datos por periodo del run.
 *
 * Este step maneja dos escenarios:
 *
 * 1. Si periodo = "Todos Los Periodos":
 *    - NO se elimina ningún dato
 *    - Se procesan todos los registros
 *
 * 2. Si periodo = YYYYMM (ej: "202508"):
 *    - En BASCAR: Extrae periodo de fecha_inicio_vig (DD/MM/YYYY → YYYYMM)
 *    - En BASCAR: Elimina registros donde periodo != periodo_run
 *    - En PAGAPL: Filtra por sheet_name que contenga el año (YYYY) del periodo
 *    - En PAGAPL: Elimina hojas donde sheet_name no contenga el año
 *
 * Nota: La columna 'periodo' ya fue creada por CreateBascarIndexesStep (paso 2)
 */
final class FilterDataByPeriodStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Filtrar datos por periodo del run';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Filtrando datos por periodo', ['run_id' => $run->id, 'period' => $run->period]);

        // Si periodo es "Todos Los Periodos", no filtrar nada
        if ($this->isAllPeriods($run->period)) {
            $bascarTotal = DB::table('data_source_bascar')->where('run_id', $run->id)->count();
            $pagaplTotal = DB::table('data_source_pagapl')->where('run_id', $run->id)->count();

            Log::info('Filtrado de datos completado (todos los periodos - sin filtrar)', [
                'run_id' => $run->id,
                'periodo' => $run->period,
                'bascar_registros' => $bascarTotal,
                'pagapl_registros' => $pagaplTotal,
                'mensaje' => 'No se eliminó ningún registro, se procesan todos los periodos',
            ]);
            return;
        }

        // Validar formato YYYYMM
        if (!$this->isValidPeriodFormat($run->period)) {
            throw new \RuntimeException(
                "Formato de periodo inválido: {$run->period}. Esperado: YYYYMM o 'Todos Los Periodos'"
            );
        }

        $this->filterBascarByPeriod($run);
        $this->filterPagaplBySheetName($run);

        // Contar registros finales
        $bascarFinal = DB::table('data_source_bascar')->where('run_id', $run->id)->count();
        $pagaplFinal = DB::table('data_source_pagapl')->where('run_id', $run->id)->count();

        Log::info('✅ Filtrado de datos completado exitosamente', [
            'run_id' => $run->id,
            'periodo' => $run->period,
            'bascar_final' => $bascarFinal,
            'pagapl_final' => $pagaplFinal,
        ]);
    }

    /**
     * Filtra tabla BASCAR por periodo:
     * 1. Extrae periodo de fecha_inicio_vig (DD/MM/YYYY o D/M/YYYY → YYYYMM)
     * 2. Elimina registros que no correspondan al periodo del run
     *
     * IMPORTANTE: Las fechas vienen normalizadas por NormalizeDateFormatsStep.
     * El binario Go excel_to_csv convierte fechas de Excel a formato MM-DD-YY.
     * NormalizeDateFormatsStep las convierte de vuelta a D/M/YYYY antes de este filtrado.
     *
     * Formatos soportados después de normalización:
     * - DD/MM/YYYY (2 dígitos día, 2 dígitos mes, 4 dígitos año)
     * - D/M/YYYY  (1-2 dígitos día, 1-2 dígitos mes, 4 dígitos año)
     *
     * Nota: La columna 'periodo' ya fue creada por CreateBascarIndexesStep (paso 2)
     */
    private function filterBascarByPeriod(CollectionNoticeRun $run): void
    {
        $tableName = 'data_source_bascar';

        // Contar registros antes del filtrado
        $totalBefore = DB::table($tableName)->where('run_id', $run->id)->count();

        // Extraer periodo de fecha_inicio_vig
        // Solo maneja formato DD/MM/YYYY o D/M/YYYY (año de 4 dígitos)
        $updated = DB::statement("
            UPDATE {$tableName}
            SET periodo = CASE
                -- Formato D/M/YYYY o DD/MM/YYYY (1-2 dígitos día/mes, 4 dígitos año)
                WHEN fecha_inicio_vig ~ '^[0-9]{1,2}/[0-9]{1,2}/[0-9]{4}$' THEN
                    CONCAT(
                        SPLIT_PART(fecha_inicio_vig, '/', 3),
                        LPAD(SPLIT_PART(fecha_inicio_vig, '/', 2), 2, '0')
                    )
                ELSE NULL
            END
            WHERE run_id = ?
            AND fecha_inicio_vig IS NOT NULL
            AND fecha_inicio_vig != ''
        ", [$run->id]);

        // Contar registros que coinciden con el periodo
        $matchingPeriod = DB::table($tableName)
            ->where('run_id', $run->id)
            ->where('periodo', $run->period)
            ->count();

        // Contar registros sin periodo (fechas inválidas)
        $withoutPeriod = DB::table($tableName)
            ->where('run_id', $run->id)
            ->whereNull('periodo')
            ->count();

        // Eliminar registros que no correspondan al periodo
        $deleted = DB::table($tableName)
            ->where('run_id', $run->id)
            ->where('periodo', '!=', $run->period)
            ->delete();

        $totalAfter = DB::table($tableName)->where('run_id', $run->id)->count();

        Log::info('Filtrado de BASCAR por periodo', [
            'run_id' => $run->id,
            'periodo_buscado' => $run->period,
            'total_antes' => $totalBefore,
            'coinciden_periodo' => $matchingPeriod,
            'sin_periodo_valido' => $withoutPeriod,
            'eliminados' => $deleted,
            'total_despues' => $totalAfter,
            'porcentaje_conservado' => $totalBefore > 0
                ? round(($totalAfter / $totalBefore) * 100, 2) . '%'
                : '0%',
        ]);
    }

    /**
     * Filtra tabla PAGAPL por sheet_name según el año del periodo:
     * 1. Extrae el año del periodo (YYYYMM → YYYY)
     * 2. Elimina registros donde sheet_name no contenga el año
     *
     * Ejemplo:
     * - Periodo: 202508 → Año: 2025
     * - Sheet names: "2020", "2021", "2022-2023", "2024-2025"
     * - Se mantienen: "2024-2025" (contiene "2025")
     * - Se eliminan: "2020", "2021", "2022-2023"
     */
    private function filterPagaplBySheetName(CollectionNoticeRun $run): void
    {
        $tableName = 'data_source_pagapl';
        $targetYear = substr($run->period, 0, 4);

        // Contar registros antes del filtrado
        $totalBefore = DB::table($tableName)->where('run_id', $run->id)->count();

        // Contar registros que contienen el año buscado
        $matching = DB::table($tableName)
            ->where('run_id', $run->id)
            ->where('sheet_name', 'LIKE', "%{$targetYear}%")
            ->count();

        // Obtener nombres únicos de hojas antes del filtrado
        $sheetsBefore = DB::table($tableName)
            ->where('run_id', $run->id)
            ->distinct()
            ->pluck('sheet_name')
            ->toArray();

        // Eliminar registros donde sheet_name NO contenga el año
        $deleted = DB::table($tableName)
            ->where('run_id', $run->id)
            ->where('sheet_name', 'NOT LIKE', "%{$targetYear}%")
            ->delete();

        $totalAfter = DB::table($tableName)->where('run_id', $run->id)->count();

        // Obtener nombres únicos de hojas después del filtrado
        $sheetsAfter = DB::table($tableName)
            ->where('run_id', $run->id)
            ->distinct()
            ->pluck('sheet_name')
            ->toArray();

        Log::info('Filtrado de PAGAPL por año', [
            'run_id' => $run->id,
            'periodo_buscado' => $run->period,
            'año_buscado' => $targetYear,
            'total_antes' => $totalBefore,
            'coinciden_año' => $matching,
            'eliminados' => $deleted,
            'total_despues' => $totalAfter,
            'hojas_antes' => $sheetsBefore,
            'hojas_despues' => $sheetsAfter,
            'hojas_eliminadas' => array_diff($sheetsBefore, $sheetsAfter),
            'porcentaje_conservado' => $totalBefore > 0
                ? round(($totalAfter / $totalBefore) * 100, 2) . '%'
                : '0%',
        ]);
    }

    /**
     * Verifica si el periodo es "Todos Los Periodos".
     */
    private function isAllPeriods(?string $period): bool
    {
        if ($period === null) {
            return false;
        }

        $normalized = strtolower(trim($period));

        return in_array($normalized, [
            'todos los periodos',
            'todos',
            'all',
            'all periods',
        ], true);
    }

    /**
     * Valida formato de periodo YYYYMM.
     */
    private function isValidPeriodFormat(?string $period): bool
    {
        if ($period === null) {
            return false;
        }

        // Formato esperado: YYYYMM (6 dígitos)
        if (!preg_match('/^\d{6}$/', $period)) {
            return false;
        }

        // Validar año razonable (2000-2099)
        $year = (int) substr($period, 0, 4);
        if ($year < 2000 || $year > 2099) {
            return false;
        }

        // Validar mes (01-12)
        $month = (int) substr($period, 4, 2);
        if ($month < 1 || $month > 12) {
            return false;
        }

        return true;
    }
}
