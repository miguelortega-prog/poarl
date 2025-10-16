<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
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
     * OPTIMIZACIÓN: Utiliza función PL/pgSQL en PostgreSQL para calcular el DV directamente
     * en la base de datos, eliminando el round-trip con PHP y procesando los 3M+ registros
     * en UN SOLO UPDATE.
     *
     * Proceso:
     * 1. Crea/actualiza función calcular_dv_nit() en PostgreSQL
     * 2. Ejecuta UN SOLO UPDATE que calcula DV para todos los registros del run
     * 3. PostgreSQL calcula el DV de forma paralela y eficiente
     *
     * Performance:
     * - Antes: ~12 minutos por lote (67 batches × escaneo completo)
     * - Después: ~20-40 segundos (1 solo UPDATE, cálculo nativo SQL)
     * - Mejora: ~20-30x más rápido
     */
    private function generateCompositeKeysWithDV(string $tableName, CollectionNoticeRun $run): void
    {
        $startTime = microtime(true);

        Log::info('Iniciando cálculo de composite_key_dv con función PL/pgSQL', [
            'run_id' => $run->id,
        ]);

        // 1. Crear/actualizar función PL/pgSQL para calcular DV
        // Esta función implementa el algoritmo oficial de la DIAN
        $this->createCalculateDvFunction();

        // 2. Verificar cantidad de registros a actualizar
        $totalRecords = DB::table($tableName)
            ->where('run_id', $run->id)
            ->whereNotNull('nit_empresa')
            ->whereNull('composite_key_dv')
            ->count();

        Log::info('Registros pendientes de actualizar', [
            'run_id' => $run->id,
            'total_records' => number_format($totalRecords),
        ]);

        if ($totalRecords === 0) {
            Log::info('No hay registros pendientes de actualizar composite_key_dv', [
                'run_id' => $run->id,
            ]);
            return;
        }

        // 3. UN SOLO UPDATE usando la función SQL
        // PostgreSQL calculará el DV de forma nativa y eficiente
        $updateStartTime = microtime(true);

        $affected = DB::update("
            UPDATE {$tableName}
            SET composite_key_dv = nit_empresa || calcular_dv_nit(nit_empresa)::TEXT || '_' || periodo_pago
            WHERE run_id = ?
                AND composite_key_dv IS NULL
                AND nit_empresa IS NOT NULL
        ", [$run->id]);

        $updateDuration = microtime(true) - $updateStartTime;

        // 4. Verificar resultado
        $updatedWithDv = DB::table($tableName)
            ->where('run_id', $run->id)
            ->whereNotNull('composite_key_dv')
            ->count();

        $totalDuration = microtime(true) - $startTime;

        Log::info('Composite keys (con DV) generadas en PAGLOG', [
            'run_id' => $run->id,
            'registros_actualizados' => number_format($affected),
            'registros_con_dv_total' => number_format($updatedWithDv),
            'tiempo_update_ms' => round($updateDuration * 1000, 2),
            'tiempo_total_ms' => round($totalDuration * 1000, 2),
            'registros_por_segundo' => $updateDuration > 0 ? round($affected / $updateDuration, 2) : 0,
        ]);
    }

    /**
     * Crea o actualiza la función PL/pgSQL para calcular el dígito de verificación.
     *
     * Implementa el algoritmo oficial de la DIAN (igual que NitHelper::calcularDigitoVerificacion).
     *
     * Algoritmo:
     * 1. Limpiar NIT (solo números)
     * 2. Invertir el NIT
     * 3. Multiplicar cada dígito por su peso correspondiente [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71]
     * 4. Sumar todos los productos
     * 5. Calcular residuo de la división por 11
     * 6. Si residuo > 1, DV = 11 - residuo, sino DV = residuo
     */
    private function createCalculateDvFunction(): void
    {
        DB::statement("
            CREATE OR REPLACE FUNCTION calcular_dv_nit(nit TEXT)
            RETURNS INTEGER AS \$function\$
            DECLARE
                pesos INTEGER[] := ARRAY[3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];
                nit_limpio TEXT;
                nit_invertido TEXT;
                suma INTEGER := 0;
                residuo INTEGER;
                longitud INTEGER;
                digito INTEGER;
                peso INTEGER;
            BEGIN
                -- Limpiar NIT (solo números)
                nit_limpio := REGEXP_REPLACE(nit, '[^0-9]', '', 'g');

                -- Validar NIT vacío o cero
                IF nit_limpio = '' OR nit_limpio = '0' THEN
                    RETURN 0;
                END IF;

                -- Invertir NIT para aplicar pesos
                nit_invertido := REVERSE(nit_limpio);
                longitud := LENGTH(nit_invertido);

                -- Calcular suma ponderada
                FOR i IN 1..longitud LOOP
                    digito := CAST(SUBSTRING(nit_invertido FROM i FOR 1) AS INTEGER);

                    -- Obtener peso correspondiente (si índice excede array, peso = 0)
                    IF i <= array_length(pesos, 1) THEN
                        peso := pesos[i];
                    ELSE
                        peso := 0;
                    END IF;

                    suma := suma + (digito * peso);
                END LOOP;

                -- Calcular dígito de verificación según normativa DIAN
                residuo := suma % 11;

                IF residuo > 1 THEN
                    RETURN 11 - residuo;
                ELSE
                    RETURN residuo;
                END IF;
            END;
            \$function\$ LANGUAGE plpgsql IMMUTABLE;
        ");

        Log::debug('Función calcular_dv_nit creada/actualizada en PostgreSQL');
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
