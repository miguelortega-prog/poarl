<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Agregar correo y dirección desde PAGPLA para registros sin datos.
 *
 * Este step es un fallback que busca correo y dirección en PAGPLA cuando
 * no se encontraron en BASACT (quedaron NULL o vacíos).
 *
 * Cruce:
 * - DETTRA.nit → PAGPLA.identificacion_aportante
 *
 * Prioridad de búsqueda:
 * 1. Busca el PRIMER registro de PAGPLA que cumpla validaciones
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
final class AddEmailAndAddressFromPagplaStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Agregar correo y dirección desde PAGPLA (fallback)';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Buscando correo y dirección en PAGPLA para registros sin datos', ['run_id' => $run->id]);

        $totalWithoutEmail = DB::table('data_source_dettra')
            ->where('run_id', $run->id)
            ->whereNull('correo')
            ->count();

        $totalWithoutAddress = DB::table('data_source_dettra')
            ->where('run_id', $run->id)
            ->whereNull('direccion')
            ->count();

        if ($totalWithoutEmail === 0 && $totalWithoutAddress === 0) {
            Log::info('Todos los registros tienen correo y dirección, búsqueda en PAGPLA omitida', ['run_id' => $run->id]);
            return;
        }

        Log::info('Registros sin datos detectados', [
            'run_id' => $run->id,
            'sin_correo' => $totalWithoutEmail,
            'sin_direccion' => $totalWithoutAddress,
        ]);

        $emailsUpdated = $this->addEmailsFromPagpla($run);
        $addressesUpdated = $this->addAddressesFromPagpla($run);

        Log::info('Correo y dirección agregados desde PAGPLA', [
            'run_id' => $run->id,
            'correos_agregados' => $emailsUpdated,
            'direcciones_agregadas' => $addressesUpdated,
            'porcentaje_correos_recuperados' => $totalWithoutEmail > 0 ? round(($emailsUpdated / $totalWithoutEmail) * 100, 2) : 0,
            'porcentaje_direcciones_recuperadas' => $totalWithoutAddress > 0 ? round(($addressesUpdated / $totalWithoutAddress) * 100, 2) : 0,
        ]);
    }

    /**
     * Actualiza DETTRA.correo con correo electrónico válido desde PAGPLA.
     *
     * Solo actualiza registros donde correo IS NULL.
     * Busca el PRIMER registro de PAGPLA que cumpla validaciones.
     *
     * Validaciones incluyen verificación contra lista negra (email_blacklist).
     *
     * @return int Cantidad de registros actualizados
     */
    private function addEmailsFromPagpla(CollectionNoticeRun $run): int
    {
        $affectedRows = DB::update("
            UPDATE data_source_dettra AS dettra
            SET correo = (
                SELECT TRIM(pagpla.email)
                FROM data_source_pagpla AS pagpla
                WHERE pagpla.run_id = ?
                    AND pagpla.identificacion_aportante = dettra.nit
                    AND pagpla.email IS NOT NULL
                    AND pagpla.email != ''
                    AND pagpla.email ~* '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$'
                    AND LOWER(pagpla.email) NOT LIKE '%@segurosbolivar.com'
                    AND LOWER(pagpla.email) NOT LIKE '%@segurosbolivar.com.co'
                    AND LOWER(pagpla.email) NOT IN (SELECT LOWER(email) FROM email_blacklist)
                ORDER BY pagpla.id
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
     * Actualiza DETTRA.direccion y DETTRA.codigo_ciudad desde PAGPLA.
     *
     * Solo actualiza registros donde dirección IS NULL.
     * Busca el PRIMER registro de PAGPLA que cumpla validaciones.
     *
     * IMPORTANTE: Si encuentra dirección válida, también actualiza codigo_ciudad (DIVIPOLA).
     *
     * @return int Cantidad de registros actualizados
     */
    private function addAddressesFromPagpla(CollectionNoticeRun $run): int
    {
        $affectedRows = DB::update("
            UPDATE data_source_dettra AS dettra
            SET
                direccion = (
                    SELECT TRIM(pagpla.direccion)
                    FROM data_source_pagpla AS pagpla
                    WHERE pagpla.run_id = ?
                        AND pagpla.identificacion_aportante = dettra.nit
                        AND pagpla.direccion IS NOT NULL
                        AND pagpla.direccion != ''
                        AND pagpla.direccion ~* '(calle|carrera|diagonal|avenida|transversal|autopista|circular|variante|cl|cr|cra|dg|av|tv|circ|var|krr)'
                        AND pagpla.direccion ~ '[0-9]'
                        AND UPPER(TRIM(pagpla.direccion)) != 'AV CALLE 26 # 68B 31 TSB'
                        AND UPPER(pagpla.direccion) NOT LIKE '%NO DEFINIDA%'
                        AND LENGTH(pagpla.direccion) >= 7
                    ORDER BY pagpla.id
                    LIMIT 1
                ),
                codigo_ciudad = (
                    SELECT CONCAT(
                        LPAD(COALESCE(pagpla.codigo_departamento, ''), 2, '0'),
                        LPAD(COALESCE(pagpla.codigo_ciudad, ''), 3, '0')
                    )
                    FROM data_source_pagpla AS pagpla
                    WHERE pagpla.run_id = ?
                        AND pagpla.identificacion_aportante = dettra.nit
                        AND pagpla.direccion IS NOT NULL
                        AND pagpla.direccion != ''
                        AND pagpla.direccion ~* '(calle|carrera|diagonal|avenida|transversal|autopista|circular|variante|cl|cr|cra|dg|av|tv|circ|var|krr)'
                        AND pagpla.direccion ~ '[0-9]'
                        AND UPPER(TRIM(pagpla.direccion)) != 'AV CALLE 26 # 68B 31 TSB'
                        AND UPPER(pagpla.direccion) NOT LIKE '%NO DEFINIDA%'
                        AND LENGTH(pagpla.direccion) >= 7
                        AND (
                            pagpla.codigo_departamento IS NOT NULL
                            OR pagpla.codigo_ciudad IS NOT NULL
                        )
                    ORDER BY pagpla.id
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
