<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\DTOs\Recaudo\Comunicados\ProcessingContextDto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Paso para contar trabajadores en DETTRA y actualizar BASCAR.
 *
 * Este paso realiza:
 * 1. Conteo de NIT únicos por NRO_DOCUMENTO en DETTRA
 * 2. Cruce de NRO_DOCUMENTO (DETTRA) con NUM_TOMADOR (BASCAR)
 * 3. Actualización de BASCAR:
 *    - Si cruza: cantidad_trabajadores = conteo, observacion_trabajadores = vacío
 *    - Si NO cruza: cantidad_trabajadores = 1, observacion_trabajadores = "Sin trabajadores activos"
 */
final readonly class CountDettraWorkersAndUpdateBascarStep implements ProcessingStepInterface
{
    private const DETTRA_CODE = 'DETTRA';
    private const BASCAR_CODE = 'BASCAR';

    /**
     * @param ProcessingContextDto $context
     *
     * @return ProcessingContextDto
     */
    public function execute(ProcessingContextDto $context): ProcessingContextDto
    {
        $run = $context->run;
        $dettraData = $context->getData(self::DETTRA_CODE);
        $bascarData = $context->getData(self::BASCAR_CODE);

        if ($dettraData === null) {
            throw new RuntimeException('No se encontró el archivo DETTRA en el contexto');
        }

        if ($bascarData === null) {
            throw new RuntimeException('No se encontró el archivo BASCAR en el contexto');
        }

        if (!($dettraData['loaded_to_db'] ?? false)) {
            throw new RuntimeException('DETTRA no está cargado en la base de datos');
        }

        if (!($bascarData['loaded_to_db'] ?? false)) {
            throw new RuntimeException('BASCAR no está cargado en la base de datos');
        }

        $dettraTable = "data_source_dettra";
        $bascarTable = "data_source_bascar";

        Log::info('Iniciando conteo de trabajadores en DETTRA y actualización de BASCAR', [
            'run_id' => $run->id,
            'dettra_table' => $dettraTable,
            'bascar_table' => $bascarTable,
        ]);

        // Paso 1: Contar registros en BASCAR antes de actualizar
        $bascarTotalRecords = DB::table($bascarTable)
            ->where('run_id', $run->id)
            ->count();

        Log::info('Registros en BASCAR antes de actualizar', [
            'run_id' => $run->id,
            'total_records' => $bascarTotalRecords,
        ]);

        // Paso 2: Crear una tabla temporal con el conteo de NITs por NRO_DOCUMENTO desde DETTRA
        Log::info('Contando NITs únicos por NRO_DOCUMENTO en DETTRA', [
            'run_id' => $run->id,
        ]);

        // Creamos una CTE para contar los NITs por NRO_DOCUMENTO
        // Extraemos NRO_DOCUMENTO y NIT desde el campo JSONB 'data'
        $workerCountsQuery = DB::table($dettraTable)
            ->select([
                DB::raw("data->>'NRO_DOCUMENTO' as nro_documento"),
                DB::raw("COUNT(DISTINCT data->>'NIT') as cantidad_trabajadores"),
            ])
            ->where('run_id', $run->id)
            ->whereNotNull(DB::raw("data->>'NRO_DOCUMENTO'"))
            ->whereNotNull(DB::raw("data->>'NIT'"))
            ->groupBy(DB::raw("data->>'NRO_DOCUMENTO'"));

        Log::debug('Query de conteo de trabajadores generada', [
            'run_id' => $run->id,
        ]);

        // Paso 3: Actualizar BASCAR - Registros que SÍ cruzan con DETTRA
        Log::info('Actualizando BASCAR para registros que cruzan con DETTRA', [
            'run_id' => $run->id,
        ]);

        $updatedWithWorkers = DB::table($bascarTable . ' as bascar')
            ->joinSub($workerCountsQuery, 'worker_counts', function ($join) {
                $join->on('bascar.num_tomador', '=', 'worker_counts.nro_documento');
            })
            ->where('bascar.run_id', $run->id)
            ->update([
                'cantidad_trabajadores' => DB::raw('worker_counts.cantidad_trabajadores::integer'),
                'observacion_trabajadores' => null,
            ]);

        Log::info('Registros actualizados con trabajadores de DETTRA', [
            'run_id' => $run->id,
            'records_updated' => $updatedWithWorkers,
        ]);

        // Paso 4: Actualizar BASCAR - Registros que NO cruzan con DETTRA
        Log::info('Actualizando BASCAR para registros sin trabajadores en DETTRA', [
            'run_id' => $run->id,
        ]);

        $updatedWithoutWorkers = DB::table($bascarTable)
            ->where('run_id', $run->id)
            ->whereNull('cantidad_trabajadores') // Solo actualizar los que aún no tienen valor
            ->update([
                'cantidad_trabajadores' => 1,
                'observacion_trabajadores' => 'Sin trabajadores activos',
            ]);

        Log::info('Registros actualizados sin trabajadores de DETTRA', [
            'run_id' => $run->id,
            'records_updated' => $updatedWithoutWorkers,
        ]);

        // Paso 5: Verificar que todos los registros fueron actualizados
        $recordsWithWorkerData = DB::table($bascarTable)
            ->where('run_id', $run->id)
            ->whereNotNull('cantidad_trabajadores')
            ->count();

        Log::info('Actualización de trabajadores completada', [
            'run_id' => $run->id,
            'total_bascar_records' => $bascarTotalRecords,
            'updated_with_workers' => $updatedWithWorkers,
            'updated_without_workers' => $updatedWithoutWorkers,
            'records_with_worker_data' => $recordsWithWorkerData,
        ]);

        // Validar que todos los registros fueron actualizados
        if ($recordsWithWorkerData !== $bascarTotalRecords) {
            Log::warning('No todos los registros de BASCAR fueron actualizados con datos de trabajadores', [
                'run_id' => $run->id,
                'expected' => $bascarTotalRecords,
                'actual' => $recordsWithWorkerData,
                'missing' => $bascarTotalRecords - $recordsWithWorkerData,
            ]);
        }

        return $context->addStepResult($this->getName(), [
            'total_bascar_records' => $bascarTotalRecords,
            'updated_with_workers' => $updatedWithWorkers,
            'updated_without_workers' => $updatedWithoutWorkers,
            'records_with_worker_data' => $recordsWithWorkerData,
        ]);
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'Contar trabajadores de DETTRA y actualizar BASCAR';
    }

    /**
     * @param ProcessingContextDto $context
     *
     * @return bool
     */
    public function shouldExecute(ProcessingContextDto $context): bool
    {
        // Solo ejecutar si DETTRA y BASCAR están cargados a BD
        $dettraData = $context->getData(self::DETTRA_CODE);
        $bascarData = $context->getData(self::BASCAR_CODE);

        return $dettraData !== null
            && $bascarData !== null
            && ($dettraData['loaded_to_db'] ?? false)
            && ($bascarData['loaded_to_db'] ?? false);
    }
}
