<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Agregar correo y dirección válidos a DETTRA desde BASACT.
 *
 * Este step cruza DETTRA con BASACT para obtener correo electrónico y dirección
 * de cada trabajador independiente, aplicando validaciones estrictas.
 *
 * Cruce:
 * - DETTRA.nit → BASACT.identificacion_trabajador
 *
 * Validaciones de correo (correo_trabajador):
 * - Formato regex válido: ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$
 * - NO pertenecer a dominio @segurosbolivar.com
 * - NO pertenecer a dominio @segurosbolivar.com.co
 *
 * Validaciones de dirección (direccion_trabajador):
 * - Contiene tipo de vía colombiana (calle, carrera, diagonal, avenida, etc.)
 * - Contiene números
 * - NO sea exactamente "AV CALLE 26 # 68B 31 TSB" (dirección Seguros Bolívar)
 * - NO contenga el texto "NO DEFINIDA"
 * - Longitud mínima: 7 caracteres
 *
 * IMPORTANTE: Este step debe ejecutarse DESPUÉS de crear las columnas correo y direccion
 * en CreateDettraIndexesStep, y preferiblemente después de agregar nombres.
 */
final class AddEmailAndAddressToDettraStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Agregar correo y dirección a DETTRA desde BASACT';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Agregando correo y dirección válidos a DETTRA desde BASACT', ['run_id' => $run->id]);

        $totalBefore = DB::table('data_source_dettra')
            ->where('run_id', $run->id)
            ->count();

        if ($totalBefore === 0) {
            Log::info('No hay registros en DETTRA para agregar correo y dirección', ['run_id' => $run->id]);
            return;
        }

        $emailsUpdated = $this->addEmailsToDettra($run);
        $addressesUpdated = $this->addAddressesToDettra($run);

        Log::info('Correo y dirección agregados a DETTRA desde BASACT', [
            'run_id' => $run->id,
            'total_dettra' => $totalBefore,
            'correos_agregados' => $emailsUpdated,
            'direcciones_agregadas' => $addressesUpdated,
            'porcentaje_con_correo' => $totalBefore > 0 ? round(($emailsUpdated / $totalBefore) * 100, 2) : 0,
            'porcentaje_con_direccion' => $totalBefore > 0 ? round(($addressesUpdated / $totalBefore) * 100, 2) : 0,
        ]);
    }

    /**
     * Actualiza DETTRA.correo con correo electrónico válido desde BASACT.
     *
     * Validaciones aplicadas:
     * - Formato de email válido (regex)
     * - NO @segurosbolivar.com
     * - NO @segurosbolivar.com.co
     * - NO estar en la lista negra (email_blacklist)
     *
     * @return int Cantidad de registros actualizados
     */
    private function addEmailsToDettra(CollectionNoticeRun $run): int
    {
        $affectedRows = DB::update("
            UPDATE data_source_dettra AS dettra
            SET correo = TRIM(basact.correo_trabajador)
            FROM data_source_basact AS basact
            WHERE dettra.run_id = ?
                AND basact.run_id = ?
                AND TRIM(COALESCE(dettra.nit, '')) = TRIM(COALESCE(basact.identificacion_trabajador, ''))
                AND TRIM(COALESCE(dettra.nit, '')) != ''
                AND dettra.correo IS NULL
                AND basact.correo_trabajador IS NOT NULL
                AND basact.correo_trabajador != ''
                AND basact.correo_trabajador ~* '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$'
                AND LOWER(basact.correo_trabajador) NOT LIKE '%@segurosbolivar.com'
                AND LOWER(basact.correo_trabajador) NOT LIKE '%@segurosbolivar.com.co'
                AND LOWER(basact.correo_trabajador) NOT IN (SELECT LOWER(email) FROM email_blacklist)
        ", [$run->id, $run->id]);

        return $affectedRows;
    }

    /**
     * Actualiza DETTRA.direccion con dirección válida desde BASACT.
     *
     * Validaciones aplicadas:
     * - Contiene tipo de vía colombiana (calle, carrera, diagonal, avenida, etc.)
     * - Contiene números
     * - NO sea "AV CALLE 26 # 68B 31 TSB" (dirección Seguros Bolívar)
     * - NO contenga "NO DEFINIDA"
     * - Longitud mínima: 7 caracteres
     *
     * @return int Cantidad de registros actualizados
     */
    private function addAddressesToDettra(CollectionNoticeRun $run): int
    {
        $affectedRows = DB::update("
            UPDATE data_source_dettra AS dettra
            SET direccion = TRIM(basact.direccion_trabajador)
            FROM data_source_basact AS basact
            WHERE dettra.run_id = ?
                AND basact.run_id = ?
                AND TRIM(COALESCE(dettra.nit, '')) = TRIM(COALESCE(basact.identificacion_trabajador, ''))
                AND TRIM(COALESCE(dettra.nit, '')) != ''
                AND dettra.direccion IS NULL
                AND basact.direccion_trabajador IS NOT NULL
                AND basact.direccion_trabajador != ''
                AND basact.direccion_trabajador ~* '(calle|carrera|diagonal|avenida|transversal|autopista|circular|variante|cl|cr|cra|dg|av|tv|circ|var|krr)'
                AND basact.direccion_trabajador ~ '[0-9]'
                AND UPPER(TRIM(basact.direccion_trabajador)) != 'AV CALLE 26 # 68B 31 TSB'
                AND UPPER(basact.direccion_trabajador) NOT LIKE '%NO DEFINIDA%'
                AND LENGTH(basact.direccion_trabajador) >= 7
        ", [$run->id, $run->id]);

        return $affectedRows;
    }
}
