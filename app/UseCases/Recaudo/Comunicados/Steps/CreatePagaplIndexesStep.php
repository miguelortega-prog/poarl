<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Preparar estructura de PAGAPL para cruces (columnas e índices).
 *
 * Este step garantiza idempotencia creando:
 * 1. Columna composite_key en PAGAPL (si no existe)
 * 2. Índices necesarios para optimizar cruces (si no existen)
 * 3. Genera composite_key para los registros de este run
 *
 * IMPORTANTE: PAGAPL es compartido entre múltiples procesadores (Aportantes e Independientes).
 * Por eso usamos nombres de columnas e índices genéricos para evitar colisiones.
 *
 * Columnas creadas:
 * - composite_key: Identifi + Periodo (de la tabla PAGAPL, NO del run)
 *
 * Índices creados:
 * - idx_pagapl_run_id: Optimiza filtros por run
 * - idx_pagapl_identifi: Optimiza búsquedas por identificación
 * - idx_pagapl_composite_key: Optimiza cruces con otras tablas
 * - idx_pagapl_run_identifi: Optimiza cruces combinados
 */
final class CreatePagaplIndexesStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Preparar estructura de PAGAPL para cruces (columnas e índices)';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Preparando estructura de PAGAPL (columnas e índices)', ['run_id' => $run->id]);

        $tableName = 'data_source_pagapl';

        // === CREAR COLUMNAS (IDEMPOTENTE) ===
        $this->ensureColumns($tableName);

        // === CREAR ÍNDICES (IDEMPOTENTE) ===
        $this->ensureIndexes($tableName);

        // === GENERAR COMPOSITE KEY PARA ESTE RUN ===
        $this->generateCompositeKeys($tableName, $run);

        Log::info('Estructura de PAGAPL preparada', ['run_id' => $run->id]);
    }

    /**
     * Asegura que todas las columnas necesarias existan en PAGAPL.
     */
    private function ensureColumns(string $tableName): void
    {
        // Columna para llaves compuestas (Identifi + Periodo de la tabla)
        $this->addColumnIfNotExists($tableName, 'composite_key', 'VARCHAR(255)');
    }

    /**
     * Asegura que todos los índices necesarios existan en PAGAPL.
     */
    private function ensureIndexes(string $tableName): void
    {
        $this->createIndexIfNotExists($tableName, 'idx_pagapl_run_id', 'run_id');
        $this->createIndexIfNotExists($tableName, 'idx_pagapl_identifi', 'identifi');
        $this->createIndexIfNotExists($tableName, 'idx_pagapl_composite_key', 'composite_key');
        $this->createIndexIfNotExists($tableName, 'idx_pagapl_run_identifi', 'run_id, identifi');
    }

    /**
     * Genera las llaves compuestas (composite_key) para los registros de este run.
     *
     * IMPORTANTE: Usa el campo 'periodo' de la tabla PAGAPL, NO el periodo del run.
     *
     * Formato: {IDENTIFI}_{PERIODO}
     * Ejemplo: 123456789_202410
     */
    private function generateCompositeKeys(string $tableName, CollectionNoticeRun $run): void
    {
        // Generar composite_key = Identifi + '_' + Periodo (de la tabla)
        DB::statement("
            UPDATE {$tableName}
            SET composite_key = CONCAT(identifi, '_', periodo)
            WHERE run_id = ?
                AND composite_key IS NULL
        ", [$run->id]);

        $updated = DB::table($tableName)
            ->where('run_id', $run->id)
            ->whereNotNull('composite_key')
            ->count();

        Log::info('Composite keys generadas en PAGAPL', [
            'run_id' => $run->id,
            'registros_actualizados' => $updated,
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
        Log::debug("Columna creada en PAGAPL", ['column' => $columnName, 'type' => $columnType]);
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
        Log::debug("Índice creado en PAGAPL", ['index' => $indexName, 'columns' => $columns]);
    }
}
