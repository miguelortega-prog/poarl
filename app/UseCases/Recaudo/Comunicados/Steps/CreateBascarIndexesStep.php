<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Preparar estructura de BASCAR (columnas e índices).
 *
 * Este step garantiza idempotencia creando:
 * 1. Todas las columnas necesarias para el pipeline (si no existen)
 * 2. Todos los índices necesarios para optimizar consultas (si no existen)
 *
 * Ejecutar este step temprano (paso 2) permite que todos los steps posteriores
 * asuman que la estructura ya está lista, evitando:
 * - Errores por columnas faltantes
 * - Fragmentación de código de creación de columnas
 * - Problemas de idempotencia en steps subsiguientes
 *
 * Columnas creadas:
 * - composite_key: Para cruces optimizados
 * - tipo_de_envio: Tipo de correspondencia (Correo/Fisico)
 * - consecutivo: Número de consecutivo único
 * - email: Email del aportante (desde PAGPLA)
 * - divipola: Código DIVIPOLA (desde PAGPLA)
 * - direccion: Dirección física (desde PAGPLA)
 * - city_code: Código ciudad (desde DATPOL)
 * - departamento: Código departamento (desde DATPOL)
 * - cantidad_trabajadores: Conteo de trabajadores (desde DETTRA)
 * - observacion_trabajadores: Observaciones sobre trabajadores
 * - psi: Póliza Seguro Independiente (desde BAPRPO)
 * - periodo: Periodo extraído de fecha_inicio_vig (formato YYYYMM)
 *
 * Índices creados:
 * - idx_bascar_tipo_envio: Optimiza loadTipoEnvioMap()
 * - idx_bascar_num_tomador: Optimiza búsquedas por NIT
 * - idx_bascar_run_id: Optimiza filtros por run
 * - idx_bascar_run_num_tomador: Optimiza cruces con filtro de run
 * - idx_bascar_psi: Optimiza filtros por PSI
 * - idx_bascar_composite_key: Optimiza cruces con PAGAPL
 * - idx_bascar_ciu_tom: Optimiza consultas de sanitización de ciudades
 * - idx_bascar_periodo: Optimiza filtros por periodo
 */
final class CreateBascarIndexesStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Preparar estructura de BASCAR (columnas e índices)';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Preparando estructura de BASCAR (columnas e índices)', ['run_id' => $run->id]);

        $tableName = 'data_source_bascar';

        // === CREAR COLUMNAS (IDEMPOTENTE) ===
        $this->ensureColumns($tableName);

        // === CREAR ÍNDICES (IDEMPOTENTE) ===
        $this->ensureIndexes($tableName);

        Log::info('Estructura de BASCAR preparada', ['run_id' => $run->id]);
    }

    /**
     * Asegura que todas las columnas necesarias existan en BASCAR.
     */
    private function ensureColumns(string $tableName): void
    {
        // Columna para llaves compuestas (cruces optimizados)
        $this->addColumnIfNotExists($tableName, 'composite_key', 'VARCHAR(255)');

        // Columnas para datos de contacto y envío
        $this->addColumnIfNotExists($tableName, 'tipo_de_envio', 'VARCHAR(20)');
        $this->addColumnIfNotExists($tableName, 'consecutivo', 'VARCHAR(100)');
        $this->addColumnIfNotExists($tableName, 'email', 'VARCHAR(255)');
        $this->addColumnIfNotExists($tableName, 'divipola', 'VARCHAR(10)');
        $this->addColumnIfNotExists($tableName, 'direccion', 'TEXT');

        // Columnas para datos de ubicación (desde DATPOL)
        $this->addColumnIfNotExists($tableName, 'city_code', 'VARCHAR(10)');
        $this->addColumnIfNotExists($tableName, 'departamento', 'VARCHAR(10)');

        // Columnas para datos de trabajadores (desde DETTRA)
        $this->addColumnIfNotExists($tableName, 'cantidad_trabajadores', 'INTEGER');
        $this->addColumnIfNotExists($tableName, 'observacion_trabajadores', 'TEXT');

        // Columna para PSI (desde BAPRPO)
        $this->addColumnIfNotExists($tableName, 'psi', 'VARCHAR(10)');

        // Columna para periodo (extraído de fecha_inicio_vig)
        $this->addColumnIfNotExists($tableName, 'periodo', 'VARCHAR(6)');
    }

    /**
     * Asegura que todos los índices necesarios existan en BASCAR.
     */
    private function ensureIndexes(string $tableName): void
    {
        $this->createIndexIfNotExists($tableName, 'idx_bascar_tipo_envio', 'tipo_de_envio');
        $this->createIndexIfNotExists($tableName, 'idx_bascar_num_tomador', 'num_tomador');
        $this->createIndexIfNotExists($tableName, 'idx_bascar_run_id', 'run_id');
        $this->createIndexIfNotExists($tableName, 'idx_bascar_run_num_tomador', 'run_id, num_tomador');
        $this->createIndexIfNotExists($tableName, 'idx_bascar_psi', 'psi');
        $this->createIndexIfNotExists($tableName, 'idx_bascar_composite_key', 'composite_key');
        $this->createIndexIfNotExists($tableName, 'idx_bascar_ciu_tom', 'ciu_tom');
        $this->createIndexIfNotExists($tableName, 'idx_bascar_periodo', 'periodo');
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
        Log::debug("Columna creada en BASCAR", ['column' => $columnName, 'type' => $columnType]);
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
        Log::debug("Índice creado en BASCAR", ['index' => $indexName, 'columns' => $columns]);
    }
}
