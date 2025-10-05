<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\DTOs\Recaudo\Comunicados\ProcessingContextDto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Paso para validar que los datos fueron cargados correctamente por los jobs previos.
 *
 * IMPORTANTE: Este step NO carga datos, solo VALIDA que los jobs previos
 * (LoadCsvDataSourcesJob y LoadExcelWithCopyJob) hayan cargado correctamente
 * todos los data sources a sus respectivas tablas en la base de datos.
 *
 * Validaciones:
 * - Verifica que todas las tablas de data sources tengan registros para este run_id
 * - Valida conteos mínimos de registros por tabla
 * - Reporta estadísticas de carga
 */
final class ValidateDataIntegrityStep implements ProcessingStepInterface
{
    /**
     * Mapeo de códigos de data sources a tablas PostgreSQL.
     */
    private const TABLE_MAP = [
        'BASCAR' => 'data_source_bascar',
        'BAPRPO' => 'data_source_baprpo',
        'DATPOL' => 'data_source_datpol',
        'DETTRA' => 'data_source_dettra',
        'PAGAPL' => 'data_source_pagapl',
        'PAGPLA' => 'data_source_pagpla',
    ];

    /**
     * @param ProcessingContextDto $context
     *
     * @return ProcessingContextDto
     */
    public function execute(ProcessingContextDto $context): ProcessingContextDto
    {
        $run = $context->run;

        Log::info('🔍 Validando integridad de datos cargados por jobs previos', [
            'step' => self::class,
            'run_id' => $run->id,
        ]);

        // Obtener data sources esperados del tipo de comunicado
        $expectedDataSources = $run->type->dataSources->pluck('code')->toArray();

        Log::info('Data sources esperados según tipo de comunicado', [
            'run_id' => $run->id,
            'expected' => $expectedDataSources,
        ]);

        $validationResults = [];
        $missingDataSources = [];
        $emptyDataSources = [];
        $totalRecords = 0;

        // Validar cada data source esperado
        foreach ($expectedDataSources as $dataSourceCode) {
            if (!isset(self::TABLE_MAP[$dataSourceCode])) {
                Log::warning('Data source no tiene tabla mapeada', [
                    'run_id' => $run->id,
                    'data_source' => $dataSourceCode,
                ]);
                $missingDataSources[] = $dataSourceCode;
                continue;
            }

            $tableName = self::TABLE_MAP[$dataSourceCode];

            // Contar registros para este run_id
            $recordCount = DB::table($tableName)
                ->where('run_id', $run->id)
                ->count();

            $validationResults[$dataSourceCode] = [
                'table' => $tableName,
                'records' => $recordCount,
            ];

            if ($recordCount === 0) {
                $emptyDataSources[] = $dataSourceCode;
                Log::error('❌ Data source sin registros en BD', [
                    'run_id' => $run->id,
                    'data_source' => $dataSourceCode,
                    'table' => $tableName,
                ]);
            } else {
                $totalRecords += $recordCount;
                Log::info('✅ Data source validado', [
                    'run_id' => $run->id,
                    'data_source' => $dataSourceCode,
                    'table' => $tableName,
                    'records' => number_format($recordCount),
                ]);
            }
        }

        // Reportar errores si hay data sources sin datos
        if (!empty($emptyDataSources)) {
            $errorMessage = sprintf(
                'Los siguientes data sources NO tienen registros en BD (jobs de carga fallaron): %s',
                implode(', ', $emptyDataSources)
            );

            Log::error('❌ Validación de integridad FALLÓ', [
                'run_id' => $run->id,
                'empty_data_sources' => $emptyDataSources,
                'validation_results' => $validationResults,
            ]);

            return $context->addError($errorMessage);
        }

        if (!empty($missingDataSources)) {
            $errorMessage = sprintf(
                'Los siguientes data sources no tienen tabla mapeada: %s',
                implode(', ', $missingDataSources)
            );

            Log::error('❌ Validación de integridad FALLÓ', [
                'run_id' => $run->id,
                'missing_data_sources' => $missingDataSources,
            ]);

            return $context->addError($errorMessage);
        }

        Log::info('✅ Validación de integridad completada exitosamente', [
            'run_id' => $run->id,
            'data_sources_validated' => count($validationResults),
            'total_records_loaded' => number_format($totalRecords),
            'validation_results' => $validationResults,
        ]);

        return $context->addStepResult($this->getName(), [
            'validation_passed' => true,
            'data_sources_validated' => count($validationResults),
            'total_records' => $totalRecords,
            'details' => $validationResults,
        ]);
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'Validar integridad de datos cargados por jobs';
    }

    /**
     * @param ProcessingContextDto $context
     *
     * @return bool
     */
    public function shouldExecute(ProcessingContextDto $context): bool
    {
        // Siempre ejecutar para validar que los jobs previos cargaron los datos
        return true;
    }
}
