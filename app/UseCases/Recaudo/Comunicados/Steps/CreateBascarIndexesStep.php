<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Crear índices en data_source_bascar para optimizar consultas.
 *
 * Crea índices de forma idempotente en:
 * - tipo_de_envio: Para optimizar loadTipoEnvioMap() en ExportBascarToExcelStep
 * - num_tomador: Para optimizar búsquedas por NIT
 * - run_id: Para optimizar filtros por run
 *
 * Los índices se crean solo si no existen (idempotente).
 */
final class CreateBascarIndexesStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Crear índices en BASCAR';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Creando índices en BASCAR', ['run_id' => $run->id]);

        $tableName = 'data_source_bascar';

        $this->createIndexIfNotExists($tableName, 'idx_bascar_tipo_envio', 'tipo_de_envio');
        $this->createIndexIfNotExists($tableName, 'idx_bascar_num_tomador', 'num_tomador');
        $this->createIndexIfNotExists($tableName, 'idx_bascar_run_id', 'run_id');
        $this->createIndexIfNotExists($tableName, 'idx_bascar_run_num_tomador', 'run_id, num_tomador');

        Log::info('Índices creados en BASCAR', ['run_id' => $run->id]);
    }

    /**
     * Crea un índice si no existe (idempotente).
     */
    private function createIndexIfNotExists(string $tableName, string $indexName, string $columns): void
    {
        $indexExists = DB::selectOne("
            SELECT 1
            FROM pg_indexes
            WHERE tablename = ?
                AND indexname = ?
        ", [$tableName, $indexName]);

        if ($indexExists) {
            return;
        }

        DB::statement("CREATE INDEX {$indexName} ON {$tableName}({$columns})");
    }
}
