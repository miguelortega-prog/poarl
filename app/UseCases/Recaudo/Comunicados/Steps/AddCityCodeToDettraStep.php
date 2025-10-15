<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Agregar código de ciudad (DIVIPOLA) a DETTRA.
 *
 * Este step genera el código DIVIPOLA completo para cada trabajador independiente
 * concatenando el código de departamento y el código de ciudad.
 *
 * Construcción del código_ciudad:
 * - cod_dpto_empresa: Formateado a 2 dígitos (LPAD con ceros)
 * - cod_ciudad_empresa: Formateado a 3 dígitos (LPAD con ceros)
 * - Resultado: código DIVIPOLA de 5 dígitos
 * - Ejemplo: Departamento "5" + Ciudad "1" → "05001" (Medellín, Antioquia)
 *
 * IMPORTANTE: Este step debe ejecutarse DESPUÉS de crear la columna codigo_ciudad
 * en CreateDettraIndexesStep.
 */
final class AddCityCodeToDettraStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Agregar código de ciudad (DIVIPOLA) a DETTRA';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Agregando código de ciudad a DETTRA', ['run_id' => $run->id]);

        $totalBefore = DB::table('data_source_dettra')
            ->where('run_id', $run->id)
            ->count();

        if ($totalBefore === 0) {
            Log::info('No hay registros en DETTRA para agregar código de ciudad', ['run_id' => $run->id]);
            return;
        }

        $updated = $this->generateCityCodes($run);

        Log::info('Códigos de ciudad agregados a DETTRA', [
            'run_id' => $run->id,
            'total_dettra' => $totalBefore,
            'codigos_generados' => $updated,
            'porcentaje_actualizado' => $totalBefore > 0 ? round(($updated / $totalBefore) * 100, 2) : 0,
        ]);
    }

    /**
     * Genera el código DIVIPOLA completo concatenando departamento y ciudad.
     *
     * Formato: DDCCC (5 dígitos)
     * - DD: Código departamento (2 dígitos con LPAD)
     * - CCC: Código ciudad (3 dígitos con LPAD)
     *
     * @return int Cantidad de registros actualizados
     */
    private function generateCityCodes(CollectionNoticeRun $run): int
    {
        // PostgreSQL usa LPAD(string, length, fill_char) para rellenar con ceros a la izquierda
        // Concatenamos: cod_dpto_empresa (2 dígitos) + cod_ciudad_empresa (3 dígitos)
        $affectedRows = DB::update("
            UPDATE data_source_dettra
            SET codigo_ciudad = CONCAT(
                LPAD(COALESCE(cod_dpto_empresa, ''), 2, '0'),
                LPAD(COALESCE(cod_ciudad_empresa, ''), 3, '0')
            )
            WHERE run_id = ?
                AND codigo_ciudad IS NULL
                AND (
                    cod_dpto_empresa IS NOT NULL
                    OR cod_ciudad_empresa IS NOT NULL
                )
        ", [$run->id]);

        // Contar registros que no pudieron ser actualizados (datos faltantes)
        $withoutData = DB::table('data_source_dettra')
            ->where('run_id', $run->id)
            ->whereNull('codigo_ciudad')
            ->count();

        if ($withoutData > 0) {
            Log::warning('Algunos registros no tienen código de departamento ni ciudad', [
                'run_id' => $run->id,
                'registros_sin_codigo' => $withoutData,
            ]);
        }

        return $affectedRows;
    }
}
