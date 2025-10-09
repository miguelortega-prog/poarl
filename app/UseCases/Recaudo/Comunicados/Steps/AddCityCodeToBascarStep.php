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
        Log::info('Agregando código de ciudad y departamento a BASCAR desde DATPOL', ['run_id' => $run->id]);

        $this->ensureCityCodeColumn();
        $this->ensureDepartamentoColumn();
        $this->updateCityCodeAndDepartamentoFromDatpol($run);

        Log::info('Código de ciudad y departamento agregados a BASCAR', ['run_id' => $run->id]);
    }

    /**
     * Asegura que exista la columna city_code en BASCAR.
     */
    private function ensureCityCodeColumn(): void
    {
        $tableName = 'data_source_bascar';

        if ($this->columnExists($tableName, 'city_code')) {
            return;
        }

        DB::statement("
            ALTER TABLE {$tableName}
            ADD COLUMN city_code VARCHAR(10)
        ");

        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_{$tableName}_city_code
            ON {$tableName}(city_code)
        ");
    }

    /**
     * Asegura que exista la columna departamento en BASCAR.
     */
    private function ensureDepartamentoColumn(): void
    {
        $tableName = 'data_source_bascar';

        if ($this->columnExists($tableName, 'departamento')) {
            return;
        }

        DB::statement("
            ALTER TABLE {$tableName}
            ADD COLUMN departamento VARCHAR(10)
        ");

        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_{$tableName}_departamento
            ON {$tableName}(departamento)
        ");
    }

    /**
     * Actualiza city_code y departamento en BASCAR con datos de DATPOL.
     *
     * - city_code: Concatena cod_dpto + cod_ciudad de DATPOL
     * - departamento: Copia cod_dpto de DATPOL
     */
    private function updateCityCodeAndDepartamentoFromDatpol(CollectionNoticeRun $run): int
    {
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
