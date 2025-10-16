<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Definir tipo de envío para cada registro de DETTRA.
 *
 * Este step determina el canal de envío del comunicado basándose en
 * los datos de contacto disponibles para cada trabajador independiente.
 *
 * Lógica de asignación:
 * - CORREO: Registros con correo válido (correo IS NOT NULL)
 * - FISICO: Registros sin correo pero con dirección válida (correo IS NULL AND direccion IS NOT NULL)
 *
 * IMPORTANTE: Este step debe ejecutarse DESPUÉS de eliminar registros sin datos de contacto,
 * garantizando que todos los registros tienen al menos un medio de contacto.
 */
final class DefineTipoDeEnvioDettraStep implements ProcessingStepInterface
{
    private const TIPO_CORREO = 'CORREO';
    private const TIPO_FISICO = 'FISICO';

    public function getName(): string
    {
        return 'Definir tipo de envío para comunicados (DETTRA)';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Definiendo tipo de envío para comunicados en DETTRA', ['run_id' => $run->id]);

        $totalRecords = DB::table('data_source_dettra')
            ->where('run_id', $run->id)
            ->count();

        if ($totalRecords === 0) {
            Log::info('No hay registros en DETTRA para definir tipo de envío', ['run_id' => $run->id]);
            return;
        }

        // Asignar tipo CORREO a registros con correo válido
        $correoCount = $this->assignTipoCorreo($run);

        // Asignar tipo FISICO a registros sin correo pero con dirección
        $fisicoCount = $this->assignTipoFisico($run);

        $totalAssigned = $correoCount + $fisicoCount;

        Log::info('Tipo de envío definido para comunicados en DETTRA', [
            'run_id' => $run->id,
            'total_registros' => $totalRecords,
            'tipo_correo' => $correoCount,
            'tipo_fisico' => $fisicoCount,
            'porcentaje_correo' => $totalRecords > 0 ? round(($correoCount / $totalRecords) * 100, 2) : 0,
            'porcentaje_fisico' => $totalRecords > 0 ? round(($fisicoCount / $totalRecords) * 100, 2) : 0,
            'total_asignado' => $totalAssigned,
        ]);

        // Verificar si hay registros sin tipo de envío (no deberían existir)
        $sinTipo = DB::table('data_source_dettra')
            ->where('run_id', $run->id)
            ->whereNull('tipo_de_envio')
            ->count();

        if ($sinTipo > 0) {
            Log::warning('Registros sin tipo de envío detectados en DETTRA', [
                'run_id' => $run->id,
                'sin_tipo' => $sinTipo,
            ]);
        }
    }

    /**
     * Asigna tipo de envío CORREO a registros con correo válido.
     *
     * @return int Cantidad de registros actualizados
     */
    private function assignTipoCorreo(CollectionNoticeRun $run): int
    {
        $affectedRows = DB::update("
            UPDATE data_source_dettra
            SET tipo_de_envio = ?
            WHERE run_id = ?
                AND correo IS NOT NULL
                AND tipo_de_envio IS NULL
        ", [self::TIPO_CORREO, $run->id]);

        return $affectedRows;
    }

    /**
     * Asigna tipo de envío FISICO a registros sin correo pero con dirección válida.
     *
     * @return int Cantidad de registros actualizados
     */
    private function assignTipoFisico(CollectionNoticeRun $run): int
    {
        $affectedRows = DB::update("
            UPDATE data_source_dettra
            SET tipo_de_envio = ?
            WHERE run_id = ?
                AND correo IS NULL
                AND direccion IS NOT NULL
                AND tipo_de_envio IS NULL
        ", [self::TIPO_FISICO, $run->id]);

        return $affectedRows;
    }
}
