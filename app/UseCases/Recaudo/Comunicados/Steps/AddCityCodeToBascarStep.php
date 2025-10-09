<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Agregar código de ciudad y departamento a BASCAR desde DATPOL.
 *
 * Este paso realiza:
 * 1. Crea columnas 'city_code' y 'departamento' (VARCHAR) en data_source_bascar
 * 2. Cruza BASCAR con DATPOL:
 *    - BASCAR.NUM_TOMADOR = DATPOL.NRO_DOCUMTO
 * 3. Para registros que cruzan:
 *    - Concatena DATPOL.cod_dpto + DATPOL.cod_ciudad → BASCAR.city_code
 *    - Copia DATPOL.cod_dpto → BASCAR.departamento
 *
 * Ejemplo: cod_dpto='05' + cod_ciudad='001' → city_code='05001', departamento='05'
 */
final class AddCityCodeToBascarStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Agregar código de ciudad y departamento a BASCAR';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        $startTime = microtime(true);

        Log::info('🏙️  Agregando código de ciudad y departamento a BASCAR desde DATPOL', [
            'step' => self::class,
            'run_id' => $run->id,
        ]);

        // Paso 1: Crear columnas city_code y departamento si no existen
        $this->ensureCityCodeColumn();
        $this->ensureDepartamentoColumn();

        // Paso 2: Contar registros totales en BASCAR
        $totalBascar = DB::table('data_source_bascar')
            ->where('run_id', $run->id)
            ->count();

        Log::info('Registros en BASCAR a actualizar', [
            'run_id' => $run->id,
            'total' => $totalBascar,
        ]);

        // Paso 3: Actualizar city_code y departamento desde DATPOL
        $updated = $this->updateCityCodeAndDepartamentoFromDatpol($run);

        // Paso 4: Verificar resultados
        $withCityCode = DB::table('data_source_bascar')
            ->where('run_id', $run->id)
            ->whereNotNull('city_code')
            ->where('city_code', '!=', '')
            ->count();

        $withDepartamento = DB::table('data_source_bascar')
            ->where('run_id', $run->id)
            ->whereNotNull('departamento')
            ->where('departamento', '!=', '')
            ->count();

        $withoutCityCode = DB::table('data_source_bascar')
            ->where('run_id', $run->id)
            ->where(function ($query) {
                $query->whereNull('city_code')
                      ->orWhere('city_code', '');
            })
            ->count();

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('✅ Código de ciudad y departamento agregados a BASCAR', [
            'run_id' => $run->id,
            'total_bascar' => $totalBascar,
            'updated' => $updated,
            'with_city_code' => $withCityCode,
            'with_departamento' => $withDepartamento,
            'without_city_code' => $withoutCityCode,
            'coverage_pct' => $totalBascar > 0 ? round(($withCityCode / $totalBascar) * 100, 2) : 0,
            'duration_ms' => $duration,
        ]);

        // Warning si muchos registros no tienen city_code
        if ($withoutCityCode > 0) {
            $pctWithoutCityCode = round(($withoutCityCode / $totalBascar) * 100, 2);

            if ($pctWithoutCityCode > 50) {
                Log::warning('⚠️  Más del 50% de registros no tienen city_code', [
                    'run_id' => $run->id,
                    'without_city_code' => $withoutCityCode,
                    'total' => $totalBascar,
                    'percent' => $pctWithoutCityCode,
                ]);
            } else {
                Log::info('Registros sin city_code (no cruzaron con DATPOL)', [
                    'run_id' => $run->id,
                    'without_city_code' => $withoutCityCode,
                    'percent' => $pctWithoutCityCode,
                ]);
            }
        }
    }

    /**
     * Asegura que exista la columna city_code en BASCAR.
     */
    private function ensureCityCodeColumn(): void
    {
        $tableName = 'data_source_bascar';

        if ($this->columnExists($tableName, 'city_code')) {
            Log::debug('Columna city_code ya existe en BASCAR', [
                'table' => $tableName,
            ]);
            return;
        }

        Log::info('Creando columna city_code en BASCAR', [
            'table' => $tableName,
        ]);

        DB::statement("
            ALTER TABLE {$tableName}
            ADD COLUMN city_code VARCHAR(10)
        ");

        // Crear índice para mejorar performance en consultas futuras
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_{$tableName}_city_code
            ON {$tableName}(city_code)
        ");

        Log::info('✅ Columna city_code creada con índice', [
            'table' => $tableName,
        ]);
    }

    /**
     * Asegura que exista la columna departamento en BASCAR.
     */
    private function ensureDepartamentoColumn(): void
    {
        $tableName = 'data_source_bascar';

        if ($this->columnExists($tableName, 'departamento')) {
            Log::debug('Columna departamento ya existe en BASCAR', [
                'table' => $tableName,
            ]);
            return;
        }

        Log::info('Creando columna departamento en BASCAR', [
            'table' => $tableName,
        ]);

        DB::statement("
            ALTER TABLE {$tableName}
            ADD COLUMN departamento VARCHAR(10)
        ");

        // Crear índice para mejorar performance en consultas futuras
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_{$tableName}_departamento
            ON {$tableName}(departamento)
        ");

        Log::info('✅ Columna departamento creada con índice', [
            'table' => $tableName,
        ]);
    }

    /**
     * Actualiza city_code y departamento en BASCAR con datos de DATPOL.
     *
     * - city_code: Concatena cod_dpto + cod_ciudad de DATPOL
     * - departamento: Copia cod_dpto de DATPOL
     */
    private function updateCityCodeAndDepartamentoFromDatpol(CollectionNoticeRun $run): int
    {
        Log::info('Actualizando city_code y departamento desde DATPOL', [
            'run_id' => $run->id,
        ]);

        // Actualizar city_code y departamento
        $updated = DB::update("
            UPDATE data_source_bascar b
            SET
                city_code = CONCAT(COALESCE(d.cod_dpto, ''), COALESCE(d.cod_ciudad, '')),
                departamento = d.cod_dpto
            FROM data_source_datpol d
            WHERE b.num_tomador = d.nro_documto
                AND b.run_id = ?
                AND d.run_id = ?
                AND b.num_tomador IS NOT NULL
                AND b.num_tomador != ''
                AND d.nro_documto IS NOT NULL
                AND d.nro_documto != ''
                AND (d.cod_dpto IS NOT NULL OR d.cod_ciudad IS NOT NULL)
        ", [$run->id, $run->id]);

        Log::info('✅ city_code y departamento actualizados desde DATPOL', [
            'run_id' => $run->id,
            'updated' => $updated,
        ]);

        return $updated;
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
