<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Agregar correo y dirección desde PAGAPL para registros sin datos.
 *
 * Este step es un fallback que busca correo y dirección en PAGAPL cuando
 * no se encontraron en BASACT (quedaron NULL o vacíos).
 *
 * Cruce:
 * - DETTRA.nit → PAGAPL.identificacion_aportante
 *
 * Prioridad de búsqueda:
 * 1. Busca el PRIMER registro de PAGAPL que cumpla validaciones
 * 2. Solo actualiza registros de DETTRA con correo IS NULL o dirección IS NULL
 *
 * Validaciones de correo (igual que en BASACT):
 * - Formato regex válido: ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$
 * - NO pertenecer a dominio @segurosbolivar.com
 * - NO pertenecer a dominio @segurosbolivar.com.co
 *
 * Validaciones de dirección (igual que en BASACT):
 * - Contiene tipo de vía colombiana (calle, carrera, diagonal, avenida, etc.)
 * - Contiene números
 * - NO sea exactamente "AV CALLE 26 # 68B 31 TSB" (dirección Seguros Bolívar)
 * - NO contenga el texto "NO DEFINIDA"
 * - Longitud mínima: 7 caracteres
 *
 * DIVIPOLA (solo si se encuentra dirección válida):
 * - Concatena: codigo_departamento (2 dígitos) + codigo_ciudad (3 dígitos)
 * - Actualiza DETTRA.codigo_ciudad solo si se actualizó la dirección
 *
 * IMPORTANTE: Este step debe ejecutarse DESPUÉS de AddEmailAndAddressToDettraStep (BASACT).
 */
final class AddEmailAndAddressFromPagaplaStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Agregar correo y dirección desde PAGAPL (fallback)';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Buscando correo y dirección en PAGAPL para registros sin datos', ['run_id' => $run->id]);

        $totalWithoutEmail = DB::table('data_source_dettra')
            ->where('run_id', $run->id)
            ->whereNull('correo')
            ->count();

        $totalWithoutAddress = DB::table('data_source_dettra')
            ->where('run_id', $run->id)
            ->whereNull('direccion')
            ->count();

        if ($totalWithoutEmail === 0 && $totalWithoutAddress === 0) {
            Log::info('Todos los registros tienen correo y dirección, búsqueda en PAGAPL omitida', ['run_id' => $run->id]);
            return;
        }

        Log::info('Registros sin datos detectados', [
            'run_id' => $run->id,
            'sin_correo' => $totalWithoutEmail,
            'sin_direccion' => $totalWithoutAddress,
        ]);

        $emailsUpdated = $this->addEmailsFromPagapla($run);
        $addressesUpdated = $this->addAddressesFromPagapla($run);

        Log::info('Correo y dirección agregados desde PAGAPL', [
            'run_id' => $run->id,
            'correos_agregados' => $emailsUpdated,
            'direcciones_agregadas' => $addressesUpdated,
            'porcentaje_correos_recuperados' => $totalWithoutEmail > 0 ? round(($emailsUpdated / $totalWithoutEmail) * 100, 2) : 0,
            'porcentaje_direcciones_recuperadas' => $totalWithoutAddress > 0 ? round(($addressesUpdated / $totalWithoutAddress) * 100, 2) : 0,
        ]);
    }

    /**
     * Actualiza DETTRA.correo con correo electrónico válido desde PAGAPL.
     *
     * Solo actualiza registros donde correo IS NULL.
     * Busca el PRIMER registro de PAGAPL que cumpla validaciones.
     *
     * @return int Cantidad de registros actualizados
     */
    private function addEmailsFromPagapla(CollectionNoticeRun $run): int
    {
        $affectedRows = DB::update("
            UPDATE data_source_dettra AS dettra
            SET correo = (
                SELECT TRIM(pagapl.email)
                FROM data_source_pagapl AS pagapl
                WHERE pagapl.run_id = ?
                    AND pagapl.identificacion_aportante = dettra.nit
                    AND pagapl.email IS NOT NULL
                    AND pagapl.email != ''
                    AND pagapl.email ~* '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$'
                    AND LOWER(pagapl.email) NOT LIKE '%@segurosbolivar.com'
                    AND LOWER(pagapl.email) NOT LIKE '%@segurosbolivar.com.co'
                ORDER BY pagapl.id
                LIMIT 1
            )
            WHERE dettra.run_id = ?
                AND dettra.correo IS NULL
                AND dettra.nit IS NOT NULL
                AND dettra.nit != ''
        ", [$run->id, $run->id]);

        return $affectedRows;
    }

    /**
     * Actualiza DETTRA.direccion y DETTRA.codigo_ciudad desde PAGAPL.
     *
     * Solo actualiza registros donde dirección IS NULL.
     * Busca el PRIMER registro de PAGAPL que cumpla validaciones.
     *
     * IMPORTANTE: Si encuentra dirección válida, también actualiza codigo_ciudad (DIVIPOLA).
     *
     * @return int Cantidad de registros actualizados
     */
    private function addAddressesFromPagapla(CollectionNoticeRun $run): int
    {
        $affectedRows = DB::update("
            UPDATE data_source_dettra AS dettra
            SET
                direccion = (
                    SELECT TRIM(pagapl.direccion)
                    FROM data_source_pagapl AS pagapl
                    WHERE pagapl.run_id = ?
                        AND pagapl.identificacion_aportante = dettra.nit
                        AND pagapl.direccion IS NOT NULL
                        AND pagapl.direccion != ''
                        AND pagapl.direccion ~* '(calle|carrera|diagonal|avenida|transversal|autopista|circular|variante|cl|cr|cra|dg|av|tv|circ|var|krr)'
                        AND pagapl.direccion ~ '[0-9]'
                        AND UPPER(TRIM(pagapl.direccion)) != 'AV CALLE 26 # 68B 31 TSB'
                        AND UPPER(pagapl.direccion) NOT LIKE '%NO DEFINIDA%'
                        AND LENGTH(pagapl.direccion) >= 7
                    ORDER BY pagapl.id
                    LIMIT 1
                ),
                codigo_ciudad = (
                    SELECT CONCAT(
                        LPAD(COALESCE(pagapl.codigo_departamento, ''), 2, '0'),
                        LPAD(COALESCE(pagapl.codigo_ciudad, ''), 3, '0')
                    )
                    FROM data_source_pagapl AS pagapl
                    WHERE pagapl.run_id = ?
                        AND pagapl.identificacion_aportante = dettra.nit
                        AND pagapl.direccion IS NOT NULL
                        AND pagapl.direccion != ''
                        AND pagapl.direccion ~* '(calle|carrera|diagonal|avenida|transversal|autopista|circular|variante|cl|cr|cra|dg|av|tv|circ|var|krr)'
                        AND pagapl.direccion ~ '[0-9]'
                        AND UPPER(TRIM(pagapl.direccion)) != 'AV CALLE 26 # 68B 31 TSB'
                        AND UPPER(pagapl.direccion) NOT LIKE '%NO DEFINIDA%'
                        AND LENGTH(pagapl.direccion) >= 7
                        AND (
                            pagapl.codigo_departamento IS NOT NULL
                            OR pagapl.codigo_ciudad IS NOT NULL
                        )
                    ORDER BY pagapl.id
                    LIMIT 1
                )
            WHERE dettra.run_id = ?
                AND dettra.direccion IS NULL
                AND dettra.nit IS NOT NULL
                AND dettra.nit != ''
        ", [$run->id, $run->id, $run->id]);

        return $affectedRows;
    }
}
