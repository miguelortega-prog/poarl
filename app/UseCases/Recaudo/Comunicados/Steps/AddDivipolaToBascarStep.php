<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Agregar DIVIPOLA y dirección válida a BASCAR desde PAGPLA.
 *
 * 1. Agrega columnas 'divipola' y 'direccion' a data_source_bascar si no existen
 * 2. Cruza BASCAR.NUM_TOMADOR con PAGPLA.identificacion_aportante
 * 3. Busca TODAS las direcciones de PAGPLA que crucen
 * 4. Selecciona la PRIMERA que cumpla:
 *    - Estructura válida de dirección colombiana (Tipo vía + número + complemento)
 *    - NO sea "AV CALLE 26 # 68B 31 TSB"
 *    - NO contenga "NO DEFINIDA"
 * 5. Obtiene divipola (codigo_departamento LPAD 2 + codigo_ciudad LPAD 3)
 *
 * Ejemplo: codigo_departamento='5', codigo_ciudad='1' → divipola='05001'
 *          direccion='Calle 11 # 23A-45 Apto 301'
 *
 * Cruce:
 * BASCAR.NUM_TOMADOR = PAGPLA.identificacion_aportante → primer dirección válida + divipola
 */
final class AddDivipolaToBascarStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Agregar DIVIPOLA y dirección a BASCAR';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        $startTime = microtime(true);

        Log::info('🗺️  Agregando DIVIPOLA y dirección válida a BASCAR desde PAGPLA', [
            'step' => self::class,
            'run_id' => $run->id,
        ]);

        // Agregar columnas divipola y direccion si no existen
        $this->ensureColumnsExist($run);

        // Poblar divipola y direccion válida desde PAGPLA
        $updatedCount = $this->populateValidAddressFromPagpla($run);

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('✅ DIVIPOLA y dirección válida agregados a BASCAR', [
            'run_id' => $run->id,
            'records_updated' => $updatedCount,
            'duration_ms' => $duration,
        ]);
    }

    /**
     * Asegura que las columnas divipola y direccion existan en data_source_bascar.
     */
    private function ensureColumnsExist(CollectionNoticeRun $run): void
    {
        // Verificar si la columna divipola existe
        $divipolaExists = DB::selectOne("
            SELECT COUNT(*) as count
            FROM information_schema.columns
            WHERE table_name = 'data_source_bascar'
                AND column_name = 'divipola'
        ")->count > 0;

        if (!$divipolaExists) {
            DB::statement("
                ALTER TABLE data_source_bascar
                ADD COLUMN divipola VARCHAR(10) NULL
            ");

            Log::info('Columna divipola creada en data_source_bascar', [
                'run_id' => $run->id,
            ]);
        }

        // Verificar si la columna direccion existe
        $direccionExists = DB::selectOne("
            SELECT COUNT(*) as count
            FROM information_schema.columns
            WHERE table_name = 'data_source_bascar'
                AND column_name = 'direccion'
        ")->count > 0;

        if (!$direccionExists) {
            DB::statement("
                ALTER TABLE data_source_bascar
                ADD COLUMN direccion TEXT NULL
            ");

            Log::info('Columna direccion creada en data_source_bascar', [
                'run_id' => $run->id,
            ]);
        }
    }

    /**
     * Pobla divipola y direccion válida desde PAGPLA.
     *
     * Busca TODAS las direcciones de PAGPLA que crucen con NUM_TOMADOR
     * y selecciona la PRIMERA que cumpla con estructura válida.
     */
    private function populateValidAddressFromPagpla(CollectionNoticeRun $run): int
    {
        Log::info('Buscando primera dirección válida desde PAGPLA', [
            'run_id' => $run->id,
        ]);

        // Usar subconsulta para obtener la primera dirección válida por cada NUM_TOMADOR
        $updated = DB::update("
            UPDATE data_source_bascar AS b
            SET
                divipola = (
                    SELECT CONCAT(
                        LPAD(COALESCE(p.codigo_departamento, ''), 2, '0'),
                        LPAD(COALESCE(p.codigo_ciudad, ''), 3, '0')
                    )
                    FROM data_source_pagpla AS p
                    WHERE p.run_id = ?
                        AND p.identificacion_aportante = b.NUM_TOMADOR
                        AND p.direccion IS NOT NULL
                        AND p.direccion != ''
                        -- Validar que contenga tipo de vía común en Colombia (case-insensitive)
                        AND p.direccion ~* '(calle|carrera|diagonal|avenida|transversal|autopista|circular|variante|cl|cr|cra|dg|av|tv|circ|var|krr)'
                        -- Validar que contenga números (característica esencial de dirección)
                        AND p.direccion ~ '[0-9]'
                        -- Excluir direcciones específicas prohibidas
                        AND UPPER(TRIM(p.direccion)) != 'AV CALLE 26 # 68B 31 TSB'
                        AND UPPER(p.direccion) NOT LIKE '%NO DEFINIDA%'
                        -- Validar que tenga al menos longitud mínima razonable (ej: 'CL 1 # 2-3')
                        AND LENGTH(p.direccion) >= 7
                    ORDER BY p.id
                    LIMIT 1
                ),
                direccion = (
                    SELECT p.direccion
                    FROM data_source_pagpla AS p
                    WHERE p.run_id = ?
                        AND p.identificacion_aportante = b.NUM_TOMADOR
                        AND p.direccion IS NOT NULL
                        AND p.direccion != ''
                        AND p.direccion ~* '(calle|carrera|diagonal|avenida|transversal|autopista|circular|variante|cl|cr|cra|dg|av|tv|circ|var|krr)'
                        AND p.direccion ~ '[0-9]'
                        AND UPPER(TRIM(p.direccion)) != 'AV CALLE 26 # 68B 31 TSB'
                        AND UPPER(p.direccion) NOT LIKE '%NO DEFINIDA%'
                        AND LENGTH(p.direccion) >= 7
                    ORDER BY p.id
                    LIMIT 1
                )
            WHERE b.run_id = ?
                AND b.NUM_TOMADOR IS NOT NULL
                AND b.NUM_TOMADOR != ''
        ", [$run->id, $run->id, $run->id]);

        Log::info('DIVIPOLA y dirección válida poblados desde PAGPLA', [
            'run_id' => $run->id,
            'updated_count' => $updated,
        ]);

        return $updated;
    }
}
