<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Helpers\NitHelper;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Preparar estructura de PAGLOG para cruces (columnas e índices).
 *
 * Este step garantiza idempotencia creando:
 * 1. Columnas composite_key necesarias para cruces (si no existen)
 * 2. Índices necesarios para optimizar consultas (si no existen)
 * 3. Genera ambas composite keys para los registros de este run
 *
 * IMPORTANTE: PAGLOG requiere DOS tipos de composite keys:
 * - nit_periodo: NIT_EMPRESA + '_' + PERIODO_PAGO (sin dígito de verificación)
 * - composite_key_dv: NIT_EMPRESA_con_DV + '_' + PERIODO_PAGO (con dígito de verificación)
 *
 * El dígito de verificación se calcula usando el algoritmo oficial de la DIAN.
 *
 * Columnas creadas:
 * - nit_periodo: NIT + periodo (sin DV)
 * - composite_key_dv: NIT con DV + periodo
 *
 * Índices creados:
 * - idx_paglog_run_id: Optimiza filtros por run
 * - idx_paglog_nit_empresa: Optimiza búsquedas por NIT
 * - idx_paglog_nit_periodo: Optimiza cruces sin DV
 * - idx_paglog_composite_key_dv: Optimiza cruces con DV
 * - idx_paglog_run_nit: Optimiza cruces combinados
 */
final class CreatePaglogIndexesStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Preparar estructura de PAGLOG para cruces (columnas e índices)';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Preparando estructura de PAGLOG (columnas e índices)', ['run_id' => $run->id]);

        $tableName = 'data_source_paglog';

        // === CREAR COLUMNAS (IDEMPOTENTE) ===
        $this->ensureColumns($tableName);

        // === CREAR ÍNDICES (IDEMPOTENTE) ===
        $this->ensureIndexes($tableName);

        // === GENERAR COMPOSITE KEYS PARA ESTE RUN ===
        $this->generateCompositeKeys($tableName, $run);

        Log::info('Estructura de PAGLOG preparada', ['run_id' => $run->id]);
    }

    /**
     * Asegura que todas las columnas necesarias existan en PAGLOG.
     */
    private function ensureColumns(string $tableName): void
    {
        // Columna para llave compuesta sin dígito de verificación
        $this->addColumnIfNotExists($tableName, 'nit_periodo', 'VARCHAR(255)');

        // Columna para llave compuesta con dígito de verificación
        $this->addColumnIfNotExists($tableName, 'composite_key_dv', 'VARCHAR(255)');
    }

    /**
     * Asegura que todos los índices necesarios existan en PAGLOG.
     */
    private function ensureIndexes(string $tableName): void
    {
        $this->createIndexIfNotExists($tableName, 'idx_paglog_run_id', 'run_id');
        $this->createIndexIfNotExists($tableName, 'idx_paglog_nit_empresa', 'nit_empresa');
        $this->createIndexIfNotExists($tableName, 'idx_paglog_nit_periodo', 'nit_periodo');
        $this->createIndexIfNotExists($tableName, 'idx_paglog_composite_key_dv', 'composite_key_dv');
        $this->createIndexIfNotExists($tableName, 'idx_paglog_run_nit', 'run_id, nit_empresa');
    }

    /**
     * Genera las llaves compuestas (composite_key) para los registros de este run.
     *
     * Genera DOS tipos de llaves:
     * 1. nit_periodo: {NIT_EMPRESA}_{PERIODO_PAGO}
     *    Ejemplo: 900373123_202410
     *
     * 2. composite_key_dv: {NIT_EMPRESA}{DV}_{PERIODO_PAGO}
     *    Ejemplo: 9003731232_202410 (DV calculado = 2)
     */
    private function generateCompositeKeys(string $tableName, CollectionNoticeRun $run): void
    {
        // 1. Generar nit_periodo (sin dígito de verificación)
        DB::statement("
            UPDATE {$tableName}
            SET nit_periodo = CONCAT(nit_empresa, '_', periodo_pago)
            WHERE run_id = ?
                AND nit_periodo IS NULL
        ", [$run->id]);

        $updatedSimple = DB::table($tableName)
            ->where('run_id', $run->id)
            ->whereNotNull('nit_periodo')
            ->count();

        Log::info('Composite keys (sin DV) generadas en PAGLOG', [
            'run_id' => $run->id,
            'registros_actualizados' => $updatedSimple,
        ]);

        // 2. Generar composite_key_dv (con dígito de verificación)
        $this->generateCompositeKeysWithDV($tableName, $run);
    }

    /**
     * Genera composite_key_dv calculando el dígito de verificación para cada NIT.
     *
     * Proceso:
     * 1. Obtiene todos los NITs únicos del run
     * 2. Calcula el DV para cada NIT usando NitHelper
     * 3. Actualiza composite_key_dv = NIT + DV + '_' + PERIODO_PAGO
     */
    private function generateCompositeKeysWithDV(string $tableName, CollectionNoticeRun $run): void
    {
        // Obtener todos los NITs únicos del run
        $nits = DB::table($tableName)
            ->where('run_id', $run->id)
            ->whereNotNull('nit_empresa')
            ->distinct()
            ->pluck('nit_empresa');

        if ($nits->isEmpty()) {
            Log::warning('No hay NITs para calcular DV en PAGLOG', ['run_id' => $run->id]);
            return;
        }

        // Calcular DV para cada NIT y generar mapa
        $nitWithDvMap = [];
        foreach ($nits as $nit) {
            $nitLimpio = preg_replace('/[^0-9]/', '', (string) $nit);
            if ($nitLimpio === '') {
                continue;
            }
            $nitConDv = NitHelper::concatenarConDV($nitLimpio);
            $nitWithDvMap[$nit] = $nitConDv;
        }

        Log::debug('Mapa de NITs con DV calculado', [
            'run_id' => $run->id,
            'total_nits' => count($nitWithDvMap),
            'sample' => array_slice($nitWithDvMap, 0, 5, true),
        ]);

        // Actualizar composite_key_dv usando CASE WHEN para mapear NITs
        $caseStatements = [];
        $bindings = [];

        foreach ($nitWithDvMap as $nitOriginal => $nitConDv) {
            $caseStatements[] = "WHEN nit_empresa = ? THEN CONCAT(?, '_', periodo_pago)";
            $bindings[] = $nitOriginal;
            $bindings[] = $nitConDv;
        }

        if (empty($caseStatements)) {
            Log::warning('No se generaron case statements para composite_key_dv', ['run_id' => $run->id]);
            return;
        }

        $caseQuery = implode(' ', $caseStatements);
        $bindings[] = $run->id;

        $sql = "
            UPDATE {$tableName}
            SET composite_key_dv = CASE
                {$caseQuery}
                ELSE NULL
            END
            WHERE run_id = ?
                AND composite_key_dv IS NULL
        ";

        DB::update($sql, $bindings);

        $updatedWithDv = DB::table($tableName)
            ->where('run_id', $run->id)
            ->whereNotNull('composite_key_dv')
            ->count();

        Log::info('Composite keys (con DV) generadas en PAGLOG', [
            'run_id' => $run->id,
            'registros_actualizados' => $updatedWithDv,
        ]);
    }

    /**
     * Agrega una columna a la tabla si no existe (idempotente).
     */
    private function addColumnIfNotExists(string $tableName, string $columnName, string $columnType): void
    {
        $exists = DB::selectOne("
            SELECT 1
            FROM information_schema.columns
            WHERE table_name = ?
                AND column_name = ?
        ", [$tableName, $columnName]);

        if ($exists) {
            return;
        }

        DB::statement("ALTER TABLE {$tableName} ADD COLUMN {$columnName} {$columnType}");
        Log::debug("Columna creada en PAGLOG", ['column' => $columnName, 'type' => $columnType]);
    }

    /**
     * Crea un índice si no existe (idempotente).
     */
    private function createIndexIfNotExists(string $tableName, string $indexName, string $columns): void
    {
        $indexExists = DB::selectOne("
            SELECT 1
            FROM pg_indexes
            WHERE tablename = ?
                AND indexname = ?
        ", [$tableName, $indexName]);

        if ($indexExists) {
            return;
        }

        DB::statement("CREATE INDEX {$indexName} ON {$tableName}({$columns})");
        Log::debug("Índice creado en PAGLOG", ['index' => $indexName, 'columns' => $columns]);
    }
}
