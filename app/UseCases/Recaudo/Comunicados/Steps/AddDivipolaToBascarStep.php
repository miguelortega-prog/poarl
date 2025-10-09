<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Agregar DIVIPOLA y dirección válida a BASCAR desde DIR_TOM/CIU_TOM y PAGPLA.
 *
 * 1. Agrega columnas 'divipola' y 'direccion' a data_source_bascar si no existen
 * 2. PRIORIDAD 1: Valida y copia DIR_TOM/CIU_TOM si cumplen criterios:
 *    - DIR_TOM: Estructura válida de dirección colombiana
 *    - CIU_TOM: Código válido para construir DIVIPOLA
 *    - Divipola construido: LPAD(SUBSTRING(CIU_TOM, 1, 2), 2) + LPAD(SUBSTRING(CIU_TOM, 3), 3)
 * 3. PRIORIDAD 2: Para registros que quedaron sin datos, cruza con PAGPLA:
 *    - BASCAR.NUM_TOMADOR = PAGPLA.identificacion_aportante
 *    - Selecciona la PRIMERA dirección válida
 *
 * Criterios de validación de dirección (aplicados a ambas fuentes):
 * - Contiene tipo de vía colombiana (calle, carrera, diagonal, etc.)
 * - Contiene números
 * - NO sea "AV CALLE 26 # 68B 31 TSB"
 * - NO contenga "NO DEFINIDA"
 * - Longitud mínima: 7 caracteres
 *
 * Ejemplo DIR_TOM/CIU_TOM:
 *   DIR_TOM='Calle 11 # 23A-45', CIU_TOM='5001' → direccion='Calle 11 # 23A-45', divipola='05001'
 * Ejemplo PAGPLA:
 *   codigo_departamento='5', codigo_ciudad='1' → divipola='05001', direccion='...'
 */
final class AddDivipolaToBascarStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Agregar DIVIPOLA y dirección a BASCAR';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Agregando DIVIPOLA y dirección válida a BASCAR desde DIR_TOM/CIU_TOM y PAGPLA', ['run_id' => $run->id]);

        $this->ensureColumnsExist($run);
        $this->copyValidAddressFromDirTomCiuTom($run);
        $this->populateValidAddressFromPagpla($run);

        Log::info('DIVIPOLA y dirección válida agregados a BASCAR', ['run_id' => $run->id]);
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
        }

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
        }
    }

    /**
     * Copia DIR_TOM y CIU_TOM a direccion y divipola si cumplen criterios de validación.
     *
     * PRIORIDAD 1: Valida DIR_TOM (dirección) y CIU_TOM (ciudad) existentes en BASCAR:
     * - DIR_TOM: Estructura válida de dirección colombiana
     * - CIU_TOM: Código válido para construir DIVIPOLA
     *   - Formato: primeros 2 caracteres = departamento, resto = ciudad
     *   - Ejemplo: '5001' → divipola '05001' (dpto: 05, ciudad: 001)
     */
    private function copyValidAddressFromDirTomCiuTom(CollectionNoticeRun $run): int
    {
        $updated = DB::update("
            UPDATE data_source_bascar
            SET
                direccion = TRIM(dir_tom),
                divipola = CONCAT(
                    LPAD(SUBSTRING(ciu_tom, 1, 2), 2, '0'),
                    LPAD(SUBSTRING(ciu_tom, 3), 3, '0')
                )
            WHERE run_id = ?
                AND dir_tom IS NOT NULL
                AND dir_tom != ''
                AND dir_tom ~* '(calle|carrera|diagonal|avenida|transversal|autopista|circular|variante|cl|cr|cra|dg|av|tv|circ|var|krr)'
                AND dir_tom ~ '[0-9]'
                AND UPPER(TRIM(dir_tom)) != 'AV CALLE 26 # 68B 31 TSB'
                AND UPPER(dir_tom) NOT LIKE '%NO DEFINIDA%'
                AND LENGTH(dir_tom) >= 7
                AND ciu_tom IS NOT NULL
                AND ciu_tom != ''
                AND LENGTH(ciu_tom) >= 3
                AND ciu_tom ~ '^[0-9]+$'
        ", [$run->id]);

        return $updated;
    }

    /**
     * Pobla divipola y direccion válida desde PAGPLA solo para registros que quedaron sin datos.
     *
     * PRIORIDAD 2: Busca TODAS las direcciones de PAGPLA que crucen con NUM_TOMADOR
     * y selecciona la PRIMERA que cumpla con estructura válida.
     *
     * Solo actualiza registros donde direccion o divipola estén vacíos.
     */
    private function populateValidAddressFromPagpla(CollectionNoticeRun $run): int
    {
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
                        AND p.identificacion_aportante = b.num_tomador
                        AND p.direccion IS NOT NULL
                        AND p.direccion != ''
                        AND p.direccion ~* '(calle|carrera|diagonal|avenida|transversal|autopista|circular|variante|cl|cr|cra|dg|av|tv|circ|var|krr)'
                        AND p.direccion ~ '[0-9]'
                        AND UPPER(TRIM(p.direccion)) != 'AV CALLE 26 # 68B 31 TSB'
                        AND UPPER(p.direccion) NOT LIKE '%NO DEFINIDA%'
                        AND LENGTH(p.direccion) >= 7
                    ORDER BY p.id
                    LIMIT 1
                ),
                direccion = (
                    SELECT TRIM(p.direccion)
                    FROM data_source_pagpla AS p
                    WHERE p.run_id = ?
                        AND p.identificacion_aportante = b.num_tomador
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
                AND b.num_tomador IS NOT NULL
                AND b.num_tomador != ''
                AND (
                    (b.direccion IS NULL OR b.direccion = '')
                    OR (b.divipola IS NULL OR b.divipola = '')
                )
        ", [$run->id, $run->id, $run->id]);

        return $updated;
    }
}
