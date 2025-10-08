<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Agregar email válido a BASCAR desde PAGPLA.
 *
 * 1. Agrega columna 'email' a data_source_bascar si no existe
 * 2. Cruza BASCAR.NUM_TOMADOR con PAGPLA.identificacion_aportante
 * 3. Busca TODOS los emails de PAGPLA que crucen
 * 4. Selecciona el PRIMERO que cumpla:
 *    - Formato de email válido
 *    - NO sea de dominios @segurosbolivar.com o @segurosbolivar.com.co
 *
 * Cruce:
 * BASCAR.NUM_TOMADOR = PAGPLA.identificacion_aportante → primer email válido
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

        Log::info('📧 Agregando email válido a BASCAR desde PAGPLA', [
            'step' => self::class,
            'run_id' => $run->id,
        ]);

        // Agregar columna email si no existe
        $this->ensureEmailColumnExists($run);

        // Poblar email válido desde PAGPLA (filtrando formato y dominio)
        $updatedCount = $this->populateValidEmailFromPagpla($run);

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('✅ Email válido agregado a BASCAR', [
            'run_id' => $run->id,
            'emails_populated' => $updatedCount,
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
     * Pobla email válido desde PAGPLA.
     *
     * Busca TODOS los emails de PAGPLA que crucen con NUM_TOMADOR
     * y selecciona el PRIMERO que cumpla:
     * - Formato válido
     * - NO sea de @segurosbolivar.com o @segurosbolivar.com.co
     */
    private function populateValidEmailFromPagpla(CollectionNoticeRun $run): int
    {
        Log::info('Buscando primer email válido desde PAGPLA', [
            'run_id' => $run->id,
        ]);

        // Usar subconsulta para obtener el primer email válido por cada NUM_TOMADOR
        $updated = DB::update("
            UPDATE data_source_bascar AS b
            SET email = (
                SELECT p.email
                FROM data_source_pagpla AS p
                WHERE p.run_id = ?
                    AND p.identificacion_aportante = b.NUM_TOMADOR
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
                AND b.NUM_TOMADOR IS NOT NULL
                AND b.NUM_TOMADOR != ''
        ", [$run->id, $run->id]);

        Log::info('Emails válidos poblados desde PAGPLA', [
            'run_id' => $run->id,
            'updated_count' => $updated,
        ]);

        return $updated;
    }
}
