<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class CollectionNoticeTypeProcessorSeeder extends Seeder
{
    /**
     * Asigna el tipo de procesador a cada tipo de comunicado.
     *
     * Los identificadores usan snake_case y son descriptivos del tipo de comunicado.
     * Estos identificadores se mapean a clases de casos de uso en config/collection-notices.php
     */
    public function run(): void
    {
        $processors = [
            // Constitución en mora
            1 => 'constitucion_mora_aportantes',
            2 => 'constitucion_mora_independientes',

            // Avisos de incumplimiento
            3 => 'aviso_incumplimiento_aportantes',
            4 => 'aviso_incumplimiento_inconsistencias',
            5 => 'aviso_incumplimiento_estados_cuenta',
            6 => 'aviso_incumplimiento_independientes',

            // Avisos al ministerio
            7 => 'aviso_ministerio_aportantes',

            // Título ejecutivo
            8 => 'titulo_ejecutivo_aportantes',

            // Acciones persuasivas
            9 => 'primera_accion_persuasiva_aportantes',
            10 => 'segunda_accion_persuasiva_aportantes',
        ];

        foreach ($processors as $typeId => $processorType) {
            DB::table('collection_notice_types')
                ->where('id', $typeId)
                ->update([
                    'processor_type' => $processorType,
                    'updated_at' => now(),
                ]);
        }

        $this->command->info('✓ Procesadores asignados a los tipos de comunicado.');
    }
}
