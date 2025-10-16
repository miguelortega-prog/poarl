<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Agregar nombres completos a DETTRA desde BASACT.
 *
 * Este step realiza un cruce entre DETTRA y BASACT para obtener el nombre completo
 * de cada trabajador independiente y guardarlo en la columna DETTRA.nombres.
 *
 * Cruce:
 * - DETTRA.nit → BASACT.identificacion_trabajador
 *
 * Construcción del nombre completo:
 * - Concatena: 1_nombre_trabajador + 2_nombre_trabajador + 1_apellido_trabajador + 2_apellido_trabajador
 * - Separados por espacios
 * - Maneja valores NULL (no incluye en concatenación)
 * - Ejemplo: "JUAN CARLOS GOMEZ PEREZ"
 *
 * IMPORTANTE: Este step debe ejecutarse DESPUÉS de eliminar los registros que cruzaron
 * con recaudo, para evitar procesar trabajadores que no recibirán comunicado.
 */
final class AddNamesToDettraFromBasactStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Agregar nombres completos a DETTRA desde BASACT';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Agregando nombres a DETTRA desde BASACT', ['run_id' => $run->id]);

        $totalBefore = DB::table('data_source_dettra')
            ->where('run_id', $run->id)
            ->count();

        if ($totalBefore === 0) {
            Log::info('No hay registros en DETTRA para agregar nombres', ['run_id' => $run->id]);
            return;
        }

        $updated = $this->addNamesToDettra($run);

        // Contar cuántos registros tienen nombres válidos (no NULL, no vacío)
        $withValidNames = DB::table('data_source_dettra')
            ->where('run_id', $run->id)
            ->whereNotNull('nombres')
            ->where('nombres', '!=', '')
            ->count();

        $withoutNames = $totalBefore - $withValidNames;

        Log::info('Nombres agregados a DETTRA desde BASACT', [
            'run_id' => $run->id,
            'total_dettra' => $totalBefore,
            'registros_procesados' => $updated,
            'nombres_validos_agregados' => $withValidNames,
            'registros_sin_nombres' => $withoutNames,
            'porcentaje_con_nombres' => $totalBefore > 0 ? round(($withValidNames / $totalBefore) * 100, 2) : 0,
        ]);
    }

    /**
     * Actualiza DETTRA.nombres con el nombre completo desde BASACT.
     *
     * Concatena las 4 columnas de nombre de BASACT:
     * - 1_nombre_trabajador
     * - 2_nombre_trabajador
     * - 1_apellido_trabajador
     * - 2_apellido_trabajador
     *
     * @return int Cantidad de registros actualizados
     */
    private function addNamesToDettra(CollectionNoticeRun $run): int
    {
        // Primero verificar si las columnas existen en BASACT
        $columnsExist = DB::select("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_name = 'data_source_basact'
                AND column_name IN ('primer_nombre_trabajador', 'segundo_nombre_trabajador', 'primer_apellido_trabajador', 'segundo_apellido_trabajador')
        ");

        Log::info('Verificación de columnas en BASACT', [
            'run_id' => $run->id,
            'columnas_encontradas' => array_column($columnsExist, 'column_name'),
            'total_columnas' => count($columnsExist),
        ]);

        if (count($columnsExist) === 0) {
            Log::warning('Las columnas de nombres no existen en BASACT', [
                'run_id' => $run->id,
            ]);
            return 0;
        }

        // Verificar muestra de datos en BASACT antes del UPDATE
        $sampleData = DB::select("
            SELECT
                identificacion_trabajador,
                primer_nombre_trabajador,
                segundo_nombre_trabajador,
                primer_apellido_trabajador,
                segundo_apellido_trabajador,
                NULLIF(TRIM(
                    CONCAT_WS(' ',
                        NULLIF(TRIM(primer_nombre_trabajador), ''),
                        NULLIF(TRIM(segundo_nombre_trabajador), ''),
                        NULLIF(TRIM(primer_apellido_trabajador), ''),
                        NULLIF(TRIM(segundo_apellido_trabajador), '')
                    )
                ), '') as nombre_completo
            FROM data_source_basact
            WHERE run_id = ?
            LIMIT 5
        ", [$run->id]);

        Log::info('Muestra de datos en BASACT', [
            'run_id' => $run->id,
            'sample' => $sampleData,
        ]);

        // Contar cuántos tienen al menos un campo de nombre no vacío
        $withNames = DB::selectOne("
            SELECT COUNT(*) as count
            FROM data_source_basact
            WHERE run_id = ?
                AND (
                    (primer_nombre_trabajador IS NOT NULL AND primer_nombre_trabajador != '')
                    OR (segundo_nombre_trabajador IS NOT NULL AND segundo_nombre_trabajador != '')
                    OR (primer_apellido_trabajador IS NOT NULL AND primer_apellido_trabajador != '')
                    OR (segundo_apellido_trabajador IS NOT NULL AND segundo_apellido_trabajador != '')
                )
        ", [$run->id]);

        Log::info('Análisis de nombres en BASACT', [
            'run_id' => $run->id,
            'registros_con_al_menos_un_nombre' => $withNames->count,
        ]);

        // Usar CONCAT_WS para manejar NULLs automáticamente
        // CONCAT_WS ignora valores NULL y no requiere COALESCE
        // NULLIF convierte cadenas vacías en NULL (cuando todos los campos son NULL/vacíos)
        $affectedRows = DB::update("
            UPDATE data_source_dettra AS dettra
            SET nombres = NULLIF(TRIM(
                CONCAT_WS(' ',
                    NULLIF(TRIM(basact.primer_nombre_trabajador), ''),
                    NULLIF(TRIM(basact.segundo_nombre_trabajador), ''),
                    NULLIF(TRIM(basact.primer_apellido_trabajador), ''),
                    NULLIF(TRIM(basact.segundo_apellido_trabajador), '')
                )
            ), '')
            FROM data_source_basact AS basact
            WHERE dettra.run_id = ?
                AND basact.run_id = ?
                AND TRIM(COALESCE(dettra.nit, '')) = TRIM(COALESCE(basact.identificacion_trabajador, ''))
                AND TRIM(COALESCE(dettra.nit, '')) != ''
                AND dettra.nombres IS NULL
        ", [$run->id, $run->id]);

        // Verificar muestra después del UPDATE
        $sampleAfter = DB::select("
            SELECT nit, nombres
            FROM data_source_dettra
            WHERE run_id = ?
            LIMIT 5
        ", [$run->id]);

        Log::info('Muestra de DETTRA después del UPDATE', [
            'run_id' => $run->id,
            'sample' => $sampleAfter,
        ]);

        return $affectedRows;
    }
}
