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
        $startTime = microtime(true);

        Log::info('🔍 Iniciando filtrado de datos por periodo', [
            'step' => self::class,
            'run_id' => $run->id,
            'period' => $run->period,
        ]);

        // Si periodo es "Todos Los Periodos", no filtrar nada
        if ($this->isAllPeriods($run->period)) {
            Log::info('✅ Periodo configurado como "Todos Los Periodos", omitiendo filtrado', [
                'run_id' => $run->id,
                'period' => $run->period,
            ]);
            return;
        }

        // Validar formato YYYYMM
        if (!$this->isValidPeriodFormat($run->period)) {
            throw new \RuntimeException(
                "Formato de periodo inválido: {$run->period}. Esperado: YYYYMM o 'Todos Los Periodos'"
            );
        }

        Log::info('📊 Filtrando datos por periodo específico', [
            'run_id' => $run->id,
            'period' => $run->period,
        ]);

        // Filtrar BASCAR por periodo (YYYYMM)
        $this->filterBascarByPeriod($run);

        // Filtrar PAGAPL por año en sheet_name
        $this->filterPagaplBySheetName($run);

        // TODO: Agregar filtros para otras tablas según reglas de negocio

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('✅ Filtrado de datos completado', [
            'run_id' => $run->id,
            'period' => $run->period,
            'duration_ms' => $duration,
        ]);
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
        $runId = $run->id;
        $targetPeriod = $run->period;

        Log::info('🔧 Procesando BASCAR: Extrayendo y filtrando por periodo', [
            'run_id' => $runId,
            'table' => $tableName,
            'target_period' => $targetPeriod,
        ]);

        // Paso 1: Agregar columna 'periodo' si no existe
        if (!$this->columnExists($tableName, 'periodo')) {
            Log::info('Creando columna periodo en BASCAR', [
                'run_id' => $runId,
                'table' => $tableName,
            ]);

            DB::statement("
                ALTER TABLE {$tableName}
                ADD COLUMN periodo VARCHAR(6)
            ");

            Log::info('✅ Columna periodo creada', [
                'run_id' => $runId,
                'table' => $tableName,
            ]);
        }

        // Paso 2: Extraer periodo de fecha_inicio_vig separando por '/'
        // Formato: D/MM/YYYY o DD/MM/YYYY → YYYYMM
        // Ejemplo: "1/08/2025" → "202508", "15/08/2025" → "202508"
        Log::info('Extrayendo periodo de fecha_inicio_vig', [
            'run_id' => $runId,
            'table' => $tableName,
        ]);

        $updated = DB::statement("
            UPDATE {$tableName}
            SET periodo = CONCAT(
                SPLIT_PART(fecha_inicio_vig, '/', 3),  -- Año (YYYY)
                LPAD(SPLIT_PART(fecha_inicio_vig, '/', 2), 2, '0')  -- Mes (MM) con padding
            )
            WHERE run_id = ?
            AND fecha_inicio_vig IS NOT NULL
            AND fecha_inicio_vig != ''
            AND fecha_inicio_vig ~ '^[0-9]{1,2}/[0-9]{1,2}/[0-9]{4}$'  -- Validar formato D/M/YYYY
        ", [$runId]);

        Log::info('✅ Periodo extraído de fecha_inicio_vig', [
            'run_id' => $runId,
            'table' => $tableName,
        ]);

        // Contar registros antes de eliminar
        $totalBefore = DB::table($tableName)
            ->where('run_id', $runId)
            ->count();

        $matchingPeriod = DB::table($tableName)
            ->where('run_id', $runId)
            ->where('periodo', $targetPeriod)
            ->count();

        Log::info('Registros por periodo en BASCAR', [
            'run_id' => $runId,
            'total_records' => $totalBefore,
            'matching_period' => $matchingPeriod,
            'target_period' => $targetPeriod,
            'to_delete' => $totalBefore - $matchingPeriod,
        ]);

        // Paso 3: Eliminar registros que no correspondan al periodo
        $deleted = DB::table($tableName)
            ->where('run_id', $runId)
            ->where('periodo', '!=', $targetPeriod)
            ->delete();

        Log::info('✅ Registros eliminados de BASCAR por periodo', [
            'run_id' => $runId,
            'table' => $tableName,
            'deleted' => $deleted,
            'remaining' => $matchingPeriod,
            'period' => $targetPeriod,
        ]);

        // Validar que quedaron registros
        if ($matchingPeriod === 0) {
            Log::warning('⚠️  No quedaron registros en BASCAR después de filtrar por periodo', [
                'run_id' => $runId,
                'period' => $targetPeriod,
            ]);
        }
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
        $runId = $run->id;
        $targetPeriod = $run->period;

        // Extraer año del periodo (YYYYMM → YYYY)
        $targetYear = substr($targetPeriod, 0, 4);

        Log::info('🔧 Procesando PAGAPL: Filtrando por sheet_name según año', [
            'run_id' => $runId,
            'table' => $tableName,
            'target_period' => $targetPeriod,
            'target_year' => $targetYear,
        ]);

        // Obtener sheet_names únicos antes de filtrar
        $sheetsBefore = DB::table($tableName)
            ->where('run_id', $runId)
            ->select('sheet_name', DB::raw('COUNT(*) as count'))
            ->groupBy('sheet_name')
            ->get();

        Log::info('Hojas disponibles en PAGAPL antes de filtrar', [
            'run_id' => $runId,
            'sheets' => $sheetsBefore->map(fn($s) => [
                'name' => $s->sheet_name,
                'count' => $s->count,
            ])->toArray(),
        ]);

        // Contar registros antes de eliminar
        $totalBefore = DB::table($tableName)
            ->where('run_id', $runId)
            ->count();

        // Contar registros que coinciden con el año
        $matchingYear = DB::table($tableName)
            ->where('run_id', $runId)
            ->where('sheet_name', 'LIKE', "%{$targetYear}%")
            ->count();

        Log::info('Análisis de registros por año en PAGAPL', [
            'run_id' => $runId,
            'total_records' => $totalBefore,
            'matching_year' => $matchingYear,
            'target_year' => $targetYear,
            'to_delete' => $totalBefore - $matchingYear,
        ]);

        // Eliminar registros donde sheet_name NO contenga el año
        $deleted = DB::table($tableName)
            ->where('run_id', $runId)
            ->where('sheet_name', 'NOT LIKE', "%{$targetYear}%")
            ->delete();

        // Obtener sheet_names únicos después de filtrar
        $sheetsAfter = DB::table($tableName)
            ->where('run_id', $runId)
            ->select('sheet_name', DB::raw('COUNT(*) as count'))
            ->groupBy('sheet_name')
            ->get();

        Log::info('✅ Registros eliminados de PAGAPL por sheet_name', [
            'run_id' => $runId,
            'table' => $tableName,
            'deleted' => $deleted,
            'remaining' => $matchingYear,
            'target_year' => $targetYear,
            'remaining_sheets' => $sheetsAfter->map(fn($s) => [
                'name' => $s->sheet_name,
                'count' => $s->count,
            ])->toArray(),
        ]);

        // Validar que quedaron registros
        if ($matchingYear === 0) {
            Log::warning('⚠️  No quedaron registros en PAGAPL después de filtrar por año', [
                'run_id' => $runId,
                'target_year' => $targetYear,
                'available_sheets' => $sheetsBefore->pluck('sheet_name')->toArray(),
            ]);
        }
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
