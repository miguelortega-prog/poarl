<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Agregar email válido a BASCAR desde email_tom y PAGPLA.
 *
 * 1. Agrega columna 'email' a data_source_bascar si no existe
 * 2. PRIORIDAD 1: Valida y copia email_tom a email si cumple criterios:
 *    - Formato de email válido
 *    - NO sea de dominios @segurosbolivar.com o @segurosbolivar.com.co
 * 3. PRIORIDAD 2: Para registros que quedaron sin email, cruza con PAGPLA:
 *    - BASCAR.NUM_TOMADOR = PAGPLA.identificacion_aportante
 *    - Selecciona el PRIMERO que cumpla los mismos criterios de validación
 *
 * Criterios de validación (aplicados a ambas fuentes):
 * - Formato regex: ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$
 * - NO @segurosbolivar.com
 * - NO @segurosbolivar.com.co
 */
final class AddEmailToBascarStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Agregar email a BASCAR';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        $startTime = microtime(true);

        Log::info('📧 Agregando email válido a BASCAR desde email_tom y PAGPLA', [
            'step' => self::class,
            'run_id' => $run->id,
        ]);

        // Paso 1: Agregar columna email si no existe
        $this->ensureEmailColumnExists($run);

        // Paso 2: PRIORIDAD 1 - Copiar desde email_tom si es válido
        $fromEmailTom = $this->copyValidEmailFromEmailTom($run);

        // Paso 3: PRIORIDAD 2 - Completar desde PAGPLA solo registros vacíos
        $fromPagpla = $this->populateValidEmailFromPagpla($run);

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('✅ Email válido agregado a BASCAR', [
            'run_id' => $run->id,
            'from_email_tom' => $fromEmailTom,
            'from_pagpla' => $fromPagpla,
            'total_emails_populated' => $fromEmailTom + $fromPagpla,
            'duration_ms' => $duration,
        ]);
    }

    /**
     * Asegura que la columna email exista en data_source_bascar.
     */
    private function ensureEmailColumnExists(CollectionNoticeRun $run): void
    {
        // Verificar si la columna ya existe
        $exists = DB::selectOne("
            SELECT COUNT(*) as count
            FROM information_schema.columns
            WHERE table_name = 'data_source_bascar'
                AND column_name = 'email'
        ")->count > 0;

        if (!$exists) {
            DB::statement("
                ALTER TABLE data_source_bascar
                ADD COLUMN email VARCHAR(255) NULL
            ");

            Log::info('Columna email creada en data_source_bascar', [
                'run_id' => $run->id,
            ]);
        } else {
            Log::debug('Columna email ya existe en data_source_bascar', [
                'run_id' => $run->id,
            ]);
        }
    }

    /**
     * Copia email_tom a email si cumple criterios de validación.
     *
     * PRIORIDAD 1: Valida email_tom existente en BASCAR y lo copia a email si:
     * - No es NULL ni vacío
     * - Cumple formato válido
     * - NO es de @segurosbolivar.com o @segurosbolivar.com.co
     */
    private function copyValidEmailFromEmailTom(CollectionNoticeRun $run): int
    {
        Log::info('Copiando email_tom válido a email', [
            'run_id' => $run->id,
        ]);

        $updated = DB::update("
            UPDATE data_source_bascar
            SET email = TRIM(email_tom)
            WHERE run_id = ?
                AND email_tom IS NOT NULL
                AND email_tom != ''
                -- Validar formato de email
                AND email_tom ~* '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$'
                -- Excluir dominios de Seguros Bolivar
                AND LOWER(email_tom) NOT LIKE '%@segurosbolivar.com'
                AND LOWER(email_tom) NOT LIKE '%@segurosbolivar.com.co'
        ", [$run->id]);

        Log::info('Emails válidos copiados desde email_tom', [
            'run_id' => $run->id,
            'updated_count' => $updated,
        ]);

        return $updated;
    }

    /**
     * Pobla email válido desde PAGPLA solo para registros que quedaron sin email.
     *
     * PRIORIDAD 2: Busca TODOS los emails de PAGPLA que crucen con NUM_TOMADOR
     * y selecciona el PRIMERO que cumpla:
     * - Formato válido
     * - NO sea de @segurosbolivar.com o @segurosbolivar.com.co
     *
     * Solo actualiza registros donde email IS NULL OR email = ''
     */
    private function populateValidEmailFromPagpla(CollectionNoticeRun $run): int
    {
        Log::info('Buscando primer email válido desde PAGPLA (solo registros vacíos)', [
            'run_id' => $run->id,
        ]);

        // Usar subconsulta para obtener el primer email válido por cada num_tomador
        $updated = DB::update("
            UPDATE data_source_bascar AS b
            SET email = (
                SELECT TRIM(p.email)
                FROM data_source_pagpla AS p
                WHERE p.run_id = ?
                    AND p.identificacion_aportante = b.num_tomador
                    AND p.email IS NOT NULL
                    AND p.email != ''
                    -- Validar formato de email
                    AND p.email ~* '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$'
                    -- Excluir dominios de Seguros Bolivar
                    AND LOWER(p.email) NOT LIKE '%@segurosbolivar.com'
                    AND LOWER(p.email) NOT LIKE '%@segurosbolivar.com.co'
                ORDER BY p.id
                LIMIT 1
            )
            WHERE b.run_id = ?
                AND b.num_tomador IS NOT NULL
                AND b.num_tomador != ''
                -- NUEVO: Solo actualizar registros que quedaron sin email
                AND (b.email IS NULL OR b.email = '')
        ", [$run->id, $run->id]);

        Log::info('Emails válidos poblados desde PAGPLA', [
            'run_id' => $run->id,
            'updated_count' => $updated,
        ]);

        return $updated;
    }
}
