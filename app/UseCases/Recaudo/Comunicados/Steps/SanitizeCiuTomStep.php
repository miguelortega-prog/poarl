<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Sanitizar campo CIU_TOM en BASCAR.
 *
 * Problema: CIU_TOM debería contener código DIVIPOLA (00000: 2 dígitos depto + 3 ciudad)
 * pero algunos registros tienen el NOMBRE de la ciudad en lugar del código.
 *
 * Proceso:
 * 1. Identificar registros con CIU_TOM que NO cumplen patrón numérico
 * 2. Buscar esos valores en city_depto.name_city
 * 3. Si hay coincidencia ÚNICA → actualizar CIU_TOM con CONCAT(depto_code, city_code)
 * 4. Si hay múltiples coincidencias → dejar CIU_TOM vacío (ambiguo)
 * 5. Si no hay coincidencias → dejar CIU_TOM vacío (no se puede resolver)
 *
 * Ejemplos:
 * - CIU_TOM = "MEDELLIN" → Buscar en city_depto → Encontrar única coincidencia
 *   → Actualizar CIU_TOM = "05001"
 * - CIU_TOM = "SAN JUAN" → Buscar en city_depto → Múltiples coincidencias
 *   → Actualizar CIU_TOM = '' (vacío)
 * - CIU_TOM = "CIUDAD INEXISTENTE" → Buscar en city_depto → Sin coincidencias
 *   → Actualizar CIU_TOM = '' (vacío)
 */
final class SanitizeCiuTomStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Sanitizar CIU_TOM (convertir nombres a códigos)';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Sanitizando CIU_TOM', ['run_id' => $run->id]);

        $invalidCiuToms = $this->getInvalidCiuTomValues($run);

        if (count($invalidCiuToms) === 0) {
            Log::info('Sanitización de CIU_TOM completada', ['run_id' => $run->id]);
            return;
        }

        foreach ($invalidCiuToms as $ciuTomValue) {
            $this->processCiuTomValue($ciuTomValue, $run);
        }

        Log::info('Sanitización de CIU_TOM completada', ['run_id' => $run->id]);
    }

    /**
     * Obtiene valores únicos de CIU_TOM que NO son códigos válidos (00000).
     *
     * Un CIU_TOM válido debe:
     * - Contener solo dígitos
     * - Tener longitud entre 1-5 (se puede hacer padding)
     *
     * Retorna valores que probablemente sean nombres de ciudades.
     */
    private function getInvalidCiuTomValues(CollectionNoticeRun $run): array
    {
        $results = DB::select("
            SELECT DISTINCT TRIM(UPPER(ciu_tom)) as ciu_tom_value
            FROM data_source_bascar
            WHERE run_id = ?
                AND ciu_tom IS NOT NULL
                AND ciu_tom != ''
                -- Excluir valores puramente numéricos
                AND ciu_tom !~ '^[0-9]+$'
            ORDER BY ciu_tom_value
        ", [$run->id]);

        return array_column($results, 'ciu_tom_value');
    }

    /**
     * Procesa un valor de CIU_TOM inválido.
     *
     * Busca en city_depto por name_city y actualiza según el resultado:
     * - Coincidencia única: actualiza con el código DIVIPOLA
     * - Sin coincidencias o múltiples: pone el campo vacío
     *
     * @return array{status: string, code?: string, matches?: int}
     */
    private function processCiuTomValue(string $ciuTomValue, CollectionNoticeRun $run): array
    {
        // Buscar en city_depto por name_city (case insensitive)
        $matches = DB::table('city_depto')
            ->whereRaw('UPPER(TRIM(name_city)) = ?', [$ciuTomValue])
            ->select('depto_code', 'city_code', 'name_city', 'name_depto')
            ->get();

        $matchCount = $matches->count();

        // Caso 1: No hay coincidencias → Dejar vacío
        if ($matchCount === 0) {
            DB::update("
                UPDATE data_source_bascar
                SET ciu_tom = ''
                WHERE run_id = ?
                    AND UPPER(TRIM(ciu_tom)) = ?
            ", [$run->id, $ciuTomValue]);

            return ['status' => 'not_found'];
        }

        // Caso 2: Múltiples coincidencias (ambiguo) → Dejar vacío
        if ($matchCount > 1) {
            DB::update("
                UPDATE data_source_bascar
                SET ciu_tom = ''
                WHERE run_id = ?
                    AND UPPER(TRIM(ciu_tom)) = ?
            ", [$run->id, $ciuTomValue]);

            $cities = $matches->map(fn($m) => "{$m->name_city} ({$m->name_depto})")->toArray();

            return ['status' => 'ambiguous', 'matches' => $matchCount];
        }

        // Caso 3: Coincidencia única → Actualizar con código DIVIPOLA
        $city = $matches->first();
        $newCode = $city->depto_code . $city->city_code;

        $updated = DB::update("
            UPDATE data_source_bascar
            SET ciu_tom = ?
            WHERE run_id = ?
                AND UPPER(TRIM(ciu_tom)) = ?
        ", [$newCode, $run->id, $ciuTomValue]);

        return ['status' => 'updated', 'code' => $newCode];
    }
}
