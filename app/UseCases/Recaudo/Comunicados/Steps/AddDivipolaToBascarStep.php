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
        $startTime = microtime(true);

        Log::info('🗺️  Agregando DIVIPOLA y dirección válida a BASCAR desde DIR_TOM/CIU_TOM y PAGPLA', [
            'step' => self::class,
            'run_id' => $run->id,
        ]);

        // Paso 1: Agregar columnas divipola y direccion si no existen
        $this->ensureColumnsExist($run);

        // Paso 2: PRIORIDAD 1 - Copiar desde DIR_TOM y CIU_TOM si son válidos
        $fromDirTom = $this->copyValidAddressFromDirTomCiuTom($run);

        // Paso 3: PRIORIDAD 2 - Completar desde PAGPLA solo registros vacíos
        $fromPagpla = $this->populateValidAddressFromPagpla($run);

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('✅ DIVIPOLA y dirección válida agregados a BASCAR', [
            'run_id' => $run->id,
            'from_dir_tom_ciu_tom' => $fromDirTom,
            'from_pagpla' => $fromPagpla,
            'total_records_updated' => $fromDirTom + $fromPagpla,
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
        Log::info('Copiando DIR_TOM y CIU_TOM válidos a direccion y divipola', [
            'run_id' => $run->id,
        ]);

        $updated = DB::update("
            UPDATE data_source_bascar
            SET
                direccion = TRIM(DIR_TOM),
                divipola = CONCAT(
                    LPAD(SUBSTRING(CIU_TOM, 1, 2), 2, '0'),
                    LPAD(SUBSTRING(CIU_TOM, 3), 3, '0')
                )
            WHERE run_id = ?
                -- Validar DIR_TOM (dirección)
                AND DIR_TOM IS NOT NULL
                AND DIR_TOM != ''
                -- Validar que contenga tipo de vía común en Colombia (case-insensitive)
                AND DIR_TOM ~* '(calle|carrera|diagonal|avenida|transversal|autopista|circular|variante|cl|cr|cra|dg|av|tv|circ|var|krr)'
                -- Validar que contenga números (característica esencial de dirección)
                AND DIR_TOM ~ '[0-9]'
                -- Excluir direcciones específicas prohibidas
                AND UPPER(TRIM(DIR_TOM)) != 'AV CALLE 26 # 68B 31 TSB'
                AND UPPER(DIR_TOM) NOT LIKE '%NO DEFINIDA%'
                -- Validar que tenga al menos longitud mínima razonable (ej: 'CL 1 # 2-3')
                AND LENGTH(DIR_TOM) >= 7
                -- Validar CIU_TOM (código ciudad)
                AND CIU_TOM IS NOT NULL
                AND CIU_TOM != ''
                -- Validar que CIU_TOM tenga al menos 3 caracteres (mínimo para dpto + ciudad)
                AND LENGTH(CIU_TOM) >= 3
                -- Validar que CIU_TOM contenga solo dígitos
                AND CIU_TOM ~ '^[0-9]+$'
        ", [$run->id]);

        Log::info('Dirección y DIVIPOLA válidos copiados desde DIR_TOM y CIU_TOM', [
            'run_id' => $run->id,
            'updated_count' => $updated,
        ]);

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
        Log::info('Buscando primera dirección válida desde PAGPLA (solo registros vacíos)', [
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
                    SELECT TRIM(p.direccion)
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
                -- NUEVO: Solo actualizar registros que quedaron sin direccion o divipola
                AND (
                    (b.direccion IS NULL OR b.direccion = '')
                    OR (b.divipola IS NULL OR b.divipola = '')
                )
        ", [$run->id, $run->id, $run->id]);

        Log::info('DIVIPOLA y dirección válida poblados desde PAGPLA', [
            'run_id' => $run->id,
            'updated_count' => $updated,
        ]);

        return $updated;
    }
}
