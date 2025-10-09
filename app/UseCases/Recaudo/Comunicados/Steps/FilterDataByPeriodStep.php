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
 *    - En BASCAR: Crea columna 'periodo' extrayendo de fecha_inicio_vig (DD/MM/YYYY → YYYYMM)
 *    - En BASCAR: Elimina registros donde periodo != periodo_run
 *    - En PAGAPL: Filtra por sheet_name que contenga el año (YYYY) del periodo
 *    - En PAGAPL: Elimina hojas donde sheet_name no contenga el año
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
            Log::info('Filtrado de datos completado (todos los periodos)', ['run_id' => $run->id]);
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

        Log::info('Filtrado de datos completado', ['run_id' => $run->id, 'period' => $run->period]);
    }

    /**
     * Filtra tabla BASCAR por periodo:
     * 1. Crea columna 'periodo' si no existe
     * 2. Extrae periodo de fecha_inicio_vig (DD/MM/YYYY → YYYYMM)
     * 3. Elimina registros que no correspondan al periodo del run
     */
    private function filterBascarByPeriod(CollectionNoticeRun $run): void
    {
        $tableName = 'data_source_bascar';

        // Crear columna 'periodo' si no existe
        if (!$this->columnExists($tableName, 'periodo')) {
            DB::statement("ALTER TABLE {$tableName} ADD COLUMN periodo VARCHAR(6)");
        }

        // Extraer periodo de fecha_inicio_vig
        DB::statement("
            UPDATE {$tableName}
            SET periodo = CONCAT(
                SPLIT_PART(fecha_inicio_vig, '/', 3),
                LPAD(SPLIT_PART(fecha_inicio_vig, '/', 2), 2, '0')
            )
            WHERE run_id = ?
            AND fecha_inicio_vig IS NOT NULL
            AND fecha_inicio_vig != ''
            AND fecha_inicio_vig ~ '^[0-9]{1,2}/[0-9]{1,2}/[0-9]{4}$'
        ", [$run->id]);

        // Eliminar registros que no correspondan al periodo
        DB::table($tableName)
            ->where('run_id', $run->id)
            ->where('periodo', '!=', $run->period)
            ->delete();
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

        // Eliminar registros donde sheet_name NO contenga el año
        DB::table($tableName)
            ->where('run_id', $run->id)
            ->where('sheet_name', 'NOT LIKE', "%{$targetYear}%")
            ->delete();
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

    /**
     * Verifica si una columna existe en una tabla.
     */
    private function columnExists(string $tableName, string $columnName): bool
    {
        $result = DB::select("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_name = ?
            AND column_name = ?
        ", [$tableName, $columnName]);

        return count($result) > 0;
    }
}
