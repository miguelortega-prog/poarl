<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Optimización para data_source_bascar
        // Eliminar índice compuesto actual que no se usa eficientemente
        DB::statement('DROP INDEX IF EXISTS data_source_bascar_run_id_composite_key_index');

        // Agregar índice individual en composite_key para mejor performance en JOINs
        DB::statement('CREATE INDEX idx_bascar_composite_key ON data_source_bascar (composite_key)');

        // Recrear índice compuesto pero con composite_key primero (más selectivo en JOINs)
        DB::statement('CREATE INDEX idx_bascar_composite_run ON data_source_bascar (composite_key, run_id)');

        // Optimización para data_source_pagapl
        // Eliminar índice compuesto actual que no se usa eficientemente
        DB::statement('DROP INDEX IF EXISTS data_source_pagapl_run_id_composite_key_index');

        // Agregar índice individual en composite_key para mejor performance en JOINs
        DB::statement('CREATE INDEX idx_pagapl_composite_key ON data_source_pagapl (composite_key)');

        // Recrear índice compuesto pero con composite_key primero (más selectivo en JOINs)
        DB::statement('CREATE INDEX idx_pagapl_composite_run ON data_source_pagapl (composite_key, run_id)');

        // Actualizar estadísticas para que el query planner tome mejores decisiones
        DB::statement('ANALYZE data_source_bascar');
        DB::statement('ANALYZE data_source_pagapl');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Eliminar índices optimizados
        DB::statement('DROP INDEX IF EXISTS idx_bascar_composite_key');
        DB::statement('DROP INDEX IF EXISTS idx_bascar_composite_run');
        DB::statement('DROP INDEX IF EXISTS idx_pagapl_composite_key');
        DB::statement('DROP INDEX IF EXISTS idx_pagapl_composite_run');

        // Restaurar índices originales
        DB::statement('CREATE INDEX data_source_bascar_run_id_composite_key_index ON data_source_bascar (run_id, composite_key)');
        DB::statement('CREATE INDEX data_source_pagapl_run_id_composite_key_index ON data_source_pagapl (run_id, composite_key)');

        DB::statement('ANALYZE data_source_bascar');
        DB::statement('ANALYZE data_source_pagapl');
    }
};
