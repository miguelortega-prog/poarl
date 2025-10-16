<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Sanitizar campos clave en BASACT para mejorar cruces.
 *
 * Este step normaliza los espacios y caracteres especiales en campos críticos
 * de BASACT para garantizar que los cruces con otras tablas funcionen correctamente.
 *
 * PROBLEMA: Los archivos Excel originales contienen:
 * - Espacios al inicio/final de las celdas
 * - Múltiples espacios consecutivos entre palabras
 * - Saltos de línea (\n, \r) dentro de celdas
 * - Tabulaciones y otros caracteres de espacio en blanco
 *
 * Esto causa que los cruces fallen incluso usando TRIM(), porque TRIM() solo
 * elimina espacios al inicio/final, no los espacios internos ni saltos de línea.
 *
 * SOLUCIÓN:
 *
 * 1. Campos que NO deben tener espacios (claves de cruce):
 *    - identificacion_trabajador: NIT del trabajador (solo números)
 *    - correo_trabajador: Email (sin espacios por definición)
 *    → Se eliminan TODOS los espacios, saltos de línea y tabulaciones
 *
 * 2. Campos que deben tener espacios normalizados (datos de persona):
 *    - primer_nombre_trabajador
 *    - segundo_nombre_trabajador
 *    - primer_apellido_trabajador
 *    - segundo_apellido_trabajador
 *    - direccion_trabajador
 *    → Se eliminan espacios al inicio/final y se reducen múltiples espacios a uno solo
 *    → Se mantienen espacios simples entre palabras (ej: "JUAN CARLOS" → "JUAN CARLOS")
 *
 * IMPORTANTE: Este step debe ejecutarse ANTES de cualquier cruce con BASACT
 * (antes de AddNamesToDettraFromBasactStep y AddEmailAndAddressToDettraStep)
 */
final class SanitizeBasactFieldsStep implements ProcessingStepInterface
{
    /**
     * Tamaño del chunk para procesamiento por lotes.
     * Procesamos 10,000 registros a la vez para evitar problemas de memoria.
     */
    private const CHUNK_SIZE = 10000;

    public function getName(): string
    {
        return 'Sanitizar campos de BASACT (identificación, correo, nombres, dirección)';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Sanitizando campos de BASACT', ['run_id' => $run->id]);

        $totalRecords = DB::table('data_source_basact')
            ->where('run_id', $run->id)
            ->count();

        if ($totalRecords === 0) {
            Log::info('No hay registros en BASACT para sanitizar', ['run_id' => $run->id]);
            return;
        }

        Log::info('Iniciando sanitización de BASACT por chunks', [
            'run_id' => $run->id,
            'total_registros' => $totalRecords,
            'chunk_size' => self::CHUNK_SIZE,
            'chunks_estimados' => ceil($totalRecords / self::CHUNK_SIZE),
        ]);

        // Sanitizar campos sin espacios (identificación, correo)
        $noSpacesUpdated = $this->sanitizeNoSpaceFields($run);

        // Sanitizar campos con espacios normalizados (nombres, apellidos, dirección)
        $normalizedUpdated = $this->sanitizeNormalizedSpaceFields($run);

        // Registrar muestra de registros sanitizados
        $this->logSanitizedSample($run);

        Log::info('Campos de BASACT sanitizados exitosamente', [
            'run_id' => $run->id,
            'total_registros' => $totalRecords,
            'campos_sin_espacios_actualizados' => $noSpacesUpdated,
            'campos_normalizados_actualizados' => $normalizedUpdated,
        ]);
    }

    /**
     * Sanitiza campos que NO deben tener espacios: identificacion_trabajador, correo_trabajador.
     *
     * Elimina TODOS los espacios, saltos de línea, tabulaciones y caracteres de espacio en blanco.
     *
     * Ejemplos:
     * - "123 456 789" → "123456789"
     * - "correo @mail.com" → "correo@mail.com"
     * - "user\n@example.com" → "user@example.com"
     *
     * @return int Total de campos actualizados
     */
    private function sanitizeNoSpaceFields(CollectionNoticeRun $run): int
    {
        Log::info('Sanitizando campos sin espacios (identificación, correo)', ['run_id' => $run->id]);

        // Usamos REGEXP_REPLACE para eliminar TODOS los caracteres de espacio en blanco
        // \s incluye: espacios, tabulaciones, saltos de línea, retornos de carro, etc.
        // 'g' flag = global (reemplazar todas las ocurrencias)
        $affectedRows = DB::update("
            UPDATE data_source_basact
            SET
                identificacion_trabajador = REGEXP_REPLACE(COALESCE(identificacion_trabajador, ''), '\s', '', 'g'),
                correo_trabajador = REGEXP_REPLACE(COALESCE(correo_trabajador, ''), '\s', '', 'g')
            WHERE run_id = ?
                AND (
                    identificacion_trabajador IS NOT NULL
                    OR correo_trabajador IS NOT NULL
                )
        ", [$run->id]);

        Log::info('Campos sin espacios sanitizados', [
            'run_id' => $run->id,
            'registros_actualizados' => $affectedRows,
        ]);

        return $affectedRows;
    }

    /**
     * Sanitiza campos que deben tener espacios normalizados:
     * primer_nombre_trabajador, segundo_nombre_trabajador, primer_apellido_trabajador,
     * segundo_apellido_trabajador, direccion_trabajador.
     *
     * Normalización:
     * 1. Elimina espacios al inicio y final (TRIM)
     * 2. Reduce múltiples espacios consecutivos a uno solo
     * 3. Mantiene espacios simples entre palabras
     *
     * Ejemplos:
     * - "  JUAN   CARLOS  " → "JUAN CARLOS"
     * - "MARIA\n\nLUZ" → "MARIA LUZ"
     * - "CALLE  123  #  45-67" → "CALLE 123 # 45-67"
     *
     * @return int Total de campos actualizados
     */
    private function sanitizeNormalizedSpaceFields(CollectionNoticeRun $run): int
    {
        Log::info('Sanitizando campos con espacios normalizados (nombres, apellidos, dirección)', ['run_id' => $run->id]);

        // Estrategia:
        // 1. REGEXP_REPLACE(..., '\s+', ' ', 'g') → Reemplaza múltiples espacios por uno solo
        // 2. TRIM(...) → Elimina espacios al inicio y final
        //
        // Nota: \s+ incluye espacios, tabulaciones, saltos de línea, etc.
        $affectedRows = DB::update("
            UPDATE data_source_basact
            SET
                primer_nombre_trabajador = TRIM(REGEXP_REPLACE(COALESCE(primer_nombre_trabajador, ''), '\s+', ' ', 'g')),
                segundo_nombre_trabajador = TRIM(REGEXP_REPLACE(COALESCE(segundo_nombre_trabajador, ''), '\s+', ' ', 'g')),
                primer_apellido_trabajador = TRIM(REGEXP_REPLACE(COALESCE(primer_apellido_trabajador, ''), '\s+', ' ', 'g')),
                segundo_apellido_trabajador = TRIM(REGEXP_REPLACE(COALESCE(segundo_apellido_trabajador, ''), '\s+', ' ', 'g')),
                direccion_trabajador = TRIM(REGEXP_REPLACE(COALESCE(direccion_trabajador, ''), '\s+', ' ', 'g'))
            WHERE run_id = ?
                AND (
                    primer_nombre_trabajador IS NOT NULL
                    OR segundo_nombre_trabajador IS NOT NULL
                    OR primer_apellido_trabajador IS NOT NULL
                    OR segundo_apellido_trabajador IS NOT NULL
                    OR direccion_trabajador IS NOT NULL
                )
        ", [$run->id]);

        Log::info('Campos con espacios normalizados sanitizados', [
            'run_id' => $run->id,
            'registros_actualizados' => $affectedRows,
        ]);

        return $affectedRows;
    }

    /**
     * Registra una muestra de registros sanitizados para verificación.
     */
    private function logSanitizedSample(CollectionNoticeRun $run): void
    {
        $sample = DB::select("
            SELECT
                identificacion_trabajador,
                correo_trabajador,
                primer_nombre_trabajador,
                segundo_nombre_trabajador,
                primer_apellido_trabajador,
                segundo_apellido_trabajador,
                direccion_trabajador
            FROM data_source_basact
            WHERE run_id = ?
            LIMIT 5
        ", [$run->id]);

        if (!empty($sample)) {
            Log::info('Muestra de registros sanitizados en BASACT', [
                'run_id' => $run->id,
                'sample' => $sample,
            ]);
        }
    }
}
