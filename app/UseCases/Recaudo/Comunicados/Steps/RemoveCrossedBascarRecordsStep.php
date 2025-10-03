<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\DTOs\Recaudo\Comunicados\ProcessingContextDto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Paso para eliminar de BASCAR los registros que cruzaron con PAGAPL.
 *
 * Los registros que cruzaron con PAGAPL fueron guardados en el CSV de excluidos.
 * Este paso elimina esos registros de la tabla staging de BASCAR para dejar solo
 * los registros que NO cruzaron y que continuarán en el flujo de procesamiento.
 */
final readonly class RemoveCrossedBascarRecordsStep implements ProcessingStepInterface
{
    private const BASCAR_CODE = 'BASCAR';
    private const PAGAPL_CODE = 'PAGAPL';

    /**
     * @param ProcessingContextDto $context
     *
     * @return ProcessingContextDto
     */
    public function execute(ProcessingContextDto $context): ProcessingContextDto
    {
        $run = $context->run;
        $bascarData = $context->getData(self::BASCAR_CODE);
        $pagaplData = $context->getData(self::PAGAPL_CODE);

        if ($bascarData === null) {
            throw new RuntimeException('No se encontró el archivo BASCAR en el contexto');
        }

        if ($pagaplData === null) {
            throw new RuntimeException('No se encontró el archivo PAGAPL en el contexto');
        }

        if (!($bascarData['loaded_to_db'] ?? false)) {
            throw new RuntimeException('BASCAR no está cargado en la base de datos');
        }

        if (!($pagaplData['loaded_to_db'] ?? false)) {
            throw new RuntimeException('PAGAPL no está cargado en la base de datos');
        }

        $bascarTable = "ds_bascar_run_{$run->id}";
        $pagaplTable = "ds_pagapl_run_{$run->id}";

        Log::info('Eliminando registros de BASCAR que cruzaron con PAGAPL', [
            'run_id' => $run->id,
            'bascar_table' => $bascarTable,
            'pagapl_table' => $pagaplTable,
        ]);

        // Contar registros antes de la eliminación
        $countBefore = DB::table($bascarTable)->count();

        // Eliminar registros de BASCAR que cruzan con PAGAPL
        // Cruce por: NUMERO_ID_APORTANTE y PERIODO
        $deletedCount = DB::table($bascarTable)
            ->whereExists(function ($query) use ($pagaplTable, $bascarTable) {
                $query->select(DB::raw(1))
                    ->from($pagaplTable)
                    ->whereColumn("{$pagaplTable}.NUMERO_ID_APORTANTE", '=', "{$bascarTable}.NUMERO_ID_APORTANTE")
                    ->whereColumn("{$pagaplTable}.PERIODO", '=', "{$bascarTable}.PERIODO");
            })
            ->delete();

        $countAfter = DB::table($bascarTable)->count();

        Log::info('Registros de BASCAR eliminados exitosamente', [
            'run_id' => $run->id,
            'registros_antes' => $countBefore,
            'registros_eliminados' => $deletedCount,
            'registros_restantes' => $countAfter,
        ]);

        // Actualizar datos de BASCAR en el contexto
        return $context->addData(self::BASCAR_CODE, [
            ...$bascarData,
            'crossed_records_removed' => true,
            'rows_before_removal' => $countBefore,
            'rows_removed' => $deletedCount,
            'rows_remaining' => $countAfter,
        ])->addStepResult($this->getName(), [
            'registros_antes' => $countBefore,
            'registros_eliminados' => $deletedCount,
            'registros_restantes' => $countAfter,
        ]);
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'Eliminar registros de BASCAR que cruzaron con PAGAPL';
    }

    /**
     * @param ProcessingContextDto $context
     *
     * @return bool
     */
    public function shouldExecute(ProcessingContextDto $context): bool
    {
        // Solo ejecutar si BASCAR y PAGAPL están cargados a BD
        // y el cruce ya fue realizado
        $bascarData = $context->getData(self::BASCAR_CODE);
        $pagaplData = $context->getData(self::PAGAPL_CODE);

        return $bascarData !== null
            && $pagaplData !== null
            && ($bascarData['loaded_to_db'] ?? false)
            && ($pagaplData['loaded_to_db'] ?? false)
            && ($bascarData['crossed_with_pagapl'] ?? false)
            && !($bascarData['crossed_records_removed'] ?? false);
    }

}
