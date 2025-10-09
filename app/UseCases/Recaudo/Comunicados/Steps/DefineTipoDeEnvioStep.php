<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Definir tipo de envío de correspondencia en BASCAR.
 *
 * Define el método de envío según la disponibilidad de datos de contacto:
 *
 * 1. Si tiene email válido → tipo_de_envio = "Correo"
 * 2. Si NO tiene email PERO tiene dirección válida → tipo_de_envio = "Fisico"
 * 3. Si no tiene ninguno → tipo_de_envio = NULL
 *
 * La validación de email y dirección se realizó en pasos anteriores:
 * - AddEmailToBascarStep: validó formato de email y excluyó dominios @segurosbolivar
 * - AddDivipolaToBascarStep: validó estructura de dirección colombiana
 */
final class DefineTipoDeEnvioStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Definir tipo de envío';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Definiendo tipo de envío', ['run_id' => $run->id]);

        $this->ensureTipoDeEnvioColumnExists($run);
        $this->setTipoDeEnvioCorreo($run);
        $this->setTipoDeEnvioFisico($run);

        Log::info('Tipo de envío definido', ['run_id' => $run->id]);
    }

    /**
     * Asegura que la columna tipo_de_envio exista en data_source_bascar.
     */
    private function ensureTipoDeEnvioColumnExists(CollectionNoticeRun $run): void
    {
        $exists = DB::selectOne("
            SELECT COUNT(*) as count
            FROM information_schema.columns
            WHERE table_name = 'data_source_bascar'
                AND column_name = 'tipo_de_envio'
        ")->count > 0;

        if (!$exists) {
            DB::statement("ALTER TABLE data_source_bascar ADD COLUMN tipo_de_envio VARCHAR(20) NULL");
        }
    }

    /**
     * Asigna tipo_de_envio = "Correo" a registros con email válido.
     */
    private function setTipoDeEnvioCorreo(CollectionNoticeRun $run): void
    {
        DB::update("
            UPDATE data_source_bascar
            SET tipo_de_envio = 'Correo'
            WHERE run_id = ?
                AND email IS NOT NULL
                AND email != ''
        ", [$run->id]);
    }

    /**
     * Asigna tipo_de_envio = "Fisico" a registros sin email pero con dirección.
     */
    private function setTipoDeEnvioFisico(CollectionNoticeRun $run): void
    {
        DB::update("
            UPDATE data_source_bascar
            SET tipo_de_envio = 'Fisico'
            WHERE run_id = ?
                AND (email IS NULL OR email = '')
                AND direccion IS NOT NULL
                AND direccion != ''
        ", [$run->id]);
    }
}
