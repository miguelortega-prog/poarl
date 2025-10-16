<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Agregar nombres de ciudades a DETTRA desde city_depto.
 *
 * Este step realiza un cruce entre DETTRA y city_depto para obtener el nombre
 * legible de cada ciudad basándose en el código DIVIPOLA (codigo_ciudad).
 *
 * Cruce:
 * - DETTRA.codigo_ciudad (5 dígitos: 2 depto + 3 ciudad) → city_depto (depto_code + city_code)
 *
 * Proceso:
 * 1. Extrae los 2 primeros dígitos de codigo_ciudad como depto_code
 * 2. Extrae los 3 últimos dígitos de codigo_ciudad como city_code
 * 3. Busca en city_depto la coincidencia exacta
 * 4. Actualiza DETTRA.nombre_ciudad con name_city
 *
 * Ejemplo:
 * - codigo_ciudad = "05001" → depto_code = "05", city_code = "001"
 * - Busca en city_depto → Encuentra "MEDELLÍN"
 * - Actualiza nombre_ciudad = "MEDELLÍN"
 *
 * IMPORTANTE: Este step debe ejecutarse DESPUÉS de:
 * - AddCityCodeToDettraStep (codigo_ciudad debe estar generado)
 * - Antes de ExportDettraToExcelStep (para incluir nombres en el Excel)
 */
final class AddCityNamesToDettraStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Agregar nombres de ciudades a DETTRA desde city_depto';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Agregando nombres de ciudades a DETTRA', ['run_id' => $run->id]);

        $totalBefore = DB::table('data_source_dettra')
            ->where('run_id', $run->id)
            ->count();

        if ($totalBefore === 0) {
            Log::info('No hay registros en DETTRA para agregar nombres de ciudades', ['run_id' => $run->id]);
            return;
        }

        $updated = $this->addCityNamesToDettra($run);

        // Contar cuántos registros tienen nombres de ciudad válidos
        $withCityNames = DB::table('data_source_dettra')
            ->where('run_id', $run->id)
            ->whereNotNull('nombre_ciudad')
            ->where('nombre_ciudad', '!=', '')
            ->count();

        $withoutCityNames = $totalBefore - $withCityNames;

        Log::info('Nombres de ciudades agregados a DETTRA', [
            'run_id' => $run->id,
            'total_dettra' => $totalBefore,
            'registros_actualizados' => $updated,
            'con_nombre_ciudad' => $withCityNames,
            'sin_nombre_ciudad' => $withoutCityNames,
            'porcentaje_con_nombre' => $totalBefore > 0 ? round(($withCityNames / $totalBefore) * 100, 2) : 0,
        ]);
    }

    /**
     * Actualiza DETTRA.nombre_ciudad con el nombre desde city_depto.
     *
     * El cruce se realiza extrayendo depto_code (2 dígitos) y city_code (3 dígitos)
     * del campo codigo_ciudad de DETTRA.
     *
     * @return int Cantidad de registros actualizados
     */
    private function addCityNamesToDettra(CollectionNoticeRun $run): int
    {
        // Verificar que exista la tabla city_depto
        $tableExists = DB::selectOne("
            SELECT 1
            FROM information_schema.tables
            WHERE table_name = 'city_depto'
        ");

        if (!$tableExists) {
            Log::warning('La tabla city_depto no existe', ['run_id' => $run->id]);
            return 0;
        }

        // Actualizar nombre_ciudad usando subconsulta
        // IMPORTANTE: codigo_ciudad debe tener exactamente 5 dígitos (DDCCC)
        // - SUBSTRING(codigo_ciudad, 1, 2) extrae depto_code
        // - SUBSTRING(codigo_ciudad, 3, 3) extrae city_code
        $affectedRows = DB::update("
            UPDATE data_source_dettra AS dettra
            SET nombre_ciudad = (
                SELECT cd.name_city
                FROM city_depto AS cd
                WHERE cd.depto_code = SUBSTRING(dettra.codigo_ciudad, 1, 2)
                    AND cd.city_code = SUBSTRING(dettra.codigo_ciudad, 3, 3)
                LIMIT 1
            )
            WHERE dettra.run_id = ?
                AND dettra.nombre_ciudad IS NULL
                AND dettra.codigo_ciudad IS NOT NULL
                AND dettra.codigo_ciudad != ''
                AND LENGTH(dettra.codigo_ciudad) = 5
        ", [$run->id]);

        // Registrar muestra de ciudades actualizadas
        $sample = DB::select("
            SELECT codigo_ciudad, nombre_ciudad
            FROM data_source_dettra
            WHERE run_id = ?
                AND nombre_ciudad IS NOT NULL
            LIMIT 5
        ", [$run->id]);

        Log::info('Muestra de ciudades actualizadas', [
            'run_id' => $run->id,
            'sample' => $sample,
        ]);

        return $affectedRows;
    }
}
