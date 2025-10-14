<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Preparar estructura de DETTRA para cruces (columnas e índices).
 *
 * Este step garantiza idempotencia creando:
 * 1. Todas las columnas necesarias para el pipeline de Independientes (si no existen)
 * 2. Todos los índices necesarios para optimizar consultas (si no existen)
 *
 * IMPORTANTE: DETTRA es compartido entre múltiples procesadores (Aportantes e Independientes).
 * Por eso usamos nombres de columnas e índices específicos para evitar colisiones.
 *
 * Columnas creadas:
 * - composite_key: NIT + periodo del run (para cruces optimizados)
 * - cruce_pagapl: Estado del cruce con PAGAPL ('cruzado' o NULL)
 * - cruce_paglog: Estado del cruce con PAGLOG ('cruzado' o NULL)
 * - cruce_paglog_dv: Estado del segundo tipo de cruce con PAGLOG ('cruzado' o NULL)
 * - observacion_trabajadores: Observaciones resultantes de cruces (TEXT)
 * - nombres: Nombre completo del trabajador (desde BASACT)
 * - codigo_ciudad: Código DIVIPOLA (cod_depto_empresa + cod_ciudad_empresa)
 * - correo: Correo electrónico del trabajador
 * - direccion: Dirección del trabajador
 * - tipo_de_envio: Tipo de envío del comunicado (CORREO o FISICO)
 * - consecutivo: Consecutivo único del comunicado (formato: CON-TIPODOC-NIT-FECHA-SERIAL)
 *
 * Índices creados:
 * - idx_dettra_run_id: Optimiza filtros por run
 * - idx_dettra_nit: Optimiza búsquedas por NIT del trabajador
 * - idx_dettra_run_nit: Optimiza cruces con filtro de run + NIT
 * - idx_dettra_composite_key: Optimiza cruces con llaves compuestas
 * - idx_dettra_tipo_cotizante: Optimiza filtros por tipo de cotizante
 * - idx_dettra_run_tipo_cotizante: Optimiza filtros combinados
 */
final class CreateDettraIndexesStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Preparar estructura de DETTRA para cruces (columnas e índices)';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Preparando estructura de DETTRA (columnas e índices)', ['run_id' => $run->id]);

        $tableName = 'data_source_dettra';

        // === CREAR COLUMNAS (IDEMPOTENTE) ===
        $this->ensureColumns($tableName);

        // === CREAR ÍNDICES (IDEMPOTENTE) ===
        $this->ensureIndexes($tableName);

        // === GENERAR COMPOSITE KEY PARA ESTE RUN ===
        $this->generateCompositeKeys($tableName, $run);

        Log::info('Estructura de DETTRA preparada', ['run_id' => $run->id]);
    }

    /**
     * Asegura que todas las columnas necesarias existan en DETTRA.
     */
    private function ensureColumns(string $tableName): void
    {
        // Columna para llaves compuestas (NIT + periodo)
        $this->addColumnIfNotExists($tableName, 'composite_key', 'VARCHAR(255)');

        // Columnas para registrar cruces con otras tablas
        $this->addColumnIfNotExists($tableName, 'cruce_pagapl', 'VARCHAR(20)');
        $this->addColumnIfNotExists($tableName, 'cruce_paglog', 'VARCHAR(20)');
        $this->addColumnIfNotExists($tableName, 'cruce_paglog_dv', 'VARCHAR(20)');

        // Columna para observaciones resultantes de cruces
        $this->addColumnIfNotExists($tableName, 'observacion_trabajadores', 'TEXT');

        // Columna para nombre completo del trabajador (desde BASACT)
        $this->addColumnIfNotExists($tableName, 'nombres', 'VARCHAR(500)');

        // Columnas para datos de ubicación y contacto
        $this->addColumnIfNotExists($tableName, 'codigo_ciudad', 'VARCHAR(10)');
        $this->addColumnIfNotExists($tableName, 'correo', 'VARCHAR(255)');
        $this->addColumnIfNotExists($tableName, 'direccion', 'VARCHAR(500)');

        // Columnas para tipo de envío y consecutivo
        $this->addColumnIfNotExists($tableName, 'tipo_de_envio', 'VARCHAR(20)');
        $this->addColumnIfNotExists($tableName, 'consecutivo', 'VARCHAR(100)');
    }

    /**
     * Asegura que todos los índices necesarios existan en DETTRA.
     */
    private function ensureIndexes(string $tableName): void
    {
        $this->createIndexIfNotExists($tableName, 'idx_dettra_run_id', 'run_id');
        $this->createIndexIfNotExists($tableName, 'idx_dettra_nit', 'nit');
        $this->createIndexIfNotExists($tableName, 'idx_dettra_run_nit', 'run_id, nit');
        $this->createIndexIfNotExists($tableName, 'idx_dettra_composite_key', 'composite_key');
        $this->createIndexIfNotExists($tableName, 'idx_dettra_tipo_cotizante', 'tipo_cotizante');
        $this->createIndexIfNotExists($tableName, 'idx_dettra_run_tipo_cotizante', 'run_id, tipo_cotizante');
    }

    /**
     * Genera las llaves compuestas (composite_key) para los registros de este run.
     *
     * Formato: {NIT}_{PERIODO}
     * Ejemplo: 123456789_202410
     */
    private function generateCompositeKeys(string $tableName, CollectionNoticeRun $run): void
    {
        $period = $run->period ?? '';

        if ($period === '') {
            Log::warning('Run sin periodo definido, composite_key será solo NIT', ['run_id' => $run->id]);
        }

        // Generar composite_key = NIT + '_' + periodo
        // Usamos operador || con CAST explícito para evitar problemas de tipos de datos en PostgreSQL
        DB::statement("
            UPDATE {$tableName}
            SET composite_key = nit || '_' || CAST(? AS VARCHAR)
            WHERE run_id = ?
                AND composite_key IS NULL
        ", [$period, $run->id]);

        $updated = DB::table($tableName)
            ->where('run_id', $run->id)
            ->whereNotNull('composite_key')
            ->count();

        Log::info('Composite keys generadas en DETTRA', [
            'run_id' => $run->id,
            'periodo' => $period,
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
        Log::debug("Columna creada en DETTRA", ['column' => $columnName, 'type' => $columnType]);
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
        Log::debug("Índice creado en DETTRA", ['index' => $indexName, 'columns' => $columns]);
    }
}
