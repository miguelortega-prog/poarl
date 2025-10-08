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
        $startTime = microtime(true);

        Log::info('📮 Definiendo tipo de envío de correspondencia en BASCAR', [
            'step' => self::class,
            'run_id' => $run->id,
        ]);

        // Agregar columna tipo_de_envio si no existe
        $this->ensureTipoDeEnvioColumnExists($run);

        // Definir tipo de envío según disponibilidad de datos
        $correoCount = $this->setTipoDeEnvioCorreo($run);
        $fisicoCount = $this->setTipoDeEnvioFisico($run);

        // Contar registros sin tipo de envío
        $sinTipoEnvio = $this->countWithoutTipoDeEnvio($run);

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('✅ Tipo de envío definido en BASCAR', [
            'run_id' => $run->id,
            'correo' => $correoCount,
            'fisico' => $fisicoCount,
            'sin_tipo_envio' => $sinTipoEnvio,
            'duration_ms' => $duration,
        ]);

        // Warning si hay muchos registros sin tipo de envío
        if ($sinTipoEnvio > 0) {
            $totalBascar = DB::table('data_source_bascar')
                ->where('run_id', $run->id)
                ->count();

            $pctSinTipoEnvio = $totalBascar > 0 ? round(($sinTipoEnvio / $totalBascar) * 100, 2) : 0;

            if ($pctSinTipoEnvio > 10) {
                Log::warning('⚠️  Registros sin tipo de envío (sin email ni dirección)', [
                    'run_id' => $run->id,
                    'sin_tipo_envio' => $sinTipoEnvio,
                    'total' => $totalBascar,
                    'percent' => $pctSinTipoEnvio,
                ]);
            }
        }
    }

    /**
     * Asegura que la columna tipo_de_envio exista en data_source_bascar.
     */
    private function ensureTipoDeEnvioColumnExists(CollectionNoticeRun $run): void
    {
        // Verificar si la columna ya existe
        $exists = DB::selectOne("
            SELECT COUNT(*) as count
            FROM information_schema.columns
            WHERE table_name = 'data_source_bascar'
                AND column_name = 'tipo_de_envio'
        ")->count > 0;

        if (!$exists) {
            DB::statement("
                ALTER TABLE data_source_bascar
                ADD COLUMN tipo_de_envio VARCHAR(20) NULL
            ");

            Log::info('Columna tipo_de_envio creada en data_source_bascar', [
                'run_id' => $run->id,
            ]);
        } else {
            Log::debug('Columna tipo_de_envio ya existe en data_source_bascar', [
                'run_id' => $run->id,
            ]);
        }
    }

    /**
     * Asigna tipo_de_envio = "Correo" a registros con email válido.
     */
    private function setTipoDeEnvioCorreo(CollectionNoticeRun $run): int
    {
        Log::info('Asignando tipo_de_envio = "Correo" a registros con email', [
            'run_id' => $run->id,
        ]);

        $updated = DB::update("
            UPDATE data_source_bascar
            SET tipo_de_envio = 'Correo'
            WHERE run_id = ?
                AND email IS NOT NULL
                AND email != ''
        ", [$run->id]);

        Log::info('Tipo de envío "Correo" asignado', [
            'run_id' => $run->id,
            'updated_count' => $updated,
        ]);

        return $updated;
    }

    /**
     * Asigna tipo_de_envio = "Fisico" a registros sin email pero con dirección.
     */
    private function setTipoDeEnvioFisico(CollectionNoticeRun $run): int
    {
        Log::info('Asignando tipo_de_envio = "Fisico" a registros sin email pero con dirección', [
            'run_id' => $run->id,
        ]);

        $updated = DB::update("
            UPDATE data_source_bascar
            SET tipo_de_envio = 'Fisico'
            WHERE run_id = ?
                AND (email IS NULL OR email = '')
                AND direccion IS NOT NULL
                AND direccion != ''
        ", [$run->id]);

        Log::info('Tipo de envío "Fisico" asignado', [
            'run_id' => $run->id,
            'updated_count' => $updated,
        ]);

        return $updated;
    }

    /**
     * Cuenta registros sin tipo de envío (sin email ni dirección).
     */
    private function countWithoutTipoDeEnvio(CollectionNoticeRun $run): int
    {
        return (int) DB::selectOne("
            SELECT COUNT(*) as count
            FROM data_source_bascar
            WHERE run_id = ?
                AND tipo_de_envio IS NULL
        ", [$run->id])->count;
    }
}
