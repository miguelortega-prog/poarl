<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Agregar nombres completos a DETTRA desde BASACT.
 *
 * Este step realiza un cruce entre DETTRA y BASACT para obtener el nombre completo
 * de cada trabajador independiente y guardarlo en la columna DETTRA.nombres.
 *
 * Cruce:
 * - DETTRA.nit → BASACT.identificacion_trabajador
 *
 * Construcción del nombre completo:
 * - Concatena: 1_nombre_trabajador + 2_nombre_trabajador + 1_apellido_trabajador + 2_apellido_trabajador
 * - Separados por espacios
 * - Maneja valores NULL (no incluye en concatenación)
 * - Ejemplo: "JUAN CARLOS GOMEZ PEREZ"
 *
 * IMPORTANTE: Este step debe ejecutarse DESPUÉS de eliminar los registros que cruzaron
 * con recaudo, para evitar procesar trabajadores que no recibirán comunicado.
 */
final class AddNamesToDettraFromBasactStep implements ProcessingStepInterface
{
    public function getName(): string
    {
        return 'Agregar nombres completos a DETTRA desde BASACT';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Agregando nombres a DETTRA desde BASACT', ['run_id' => $run->id]);

        $totalBefore = DB::table('data_source_dettra')
            ->where('run_id', $run->id)
            ->count();

        if ($totalBefore === 0) {
            Log::info('No hay registros en DETTRA para agregar nombres', ['run_id' => $run->id]);
            return;
        }

        $updated = $this->addNamesToDettra($run);

        Log::info('Nombres agregados a DETTRA desde BASACT', [
            'run_id' => $run->id,
            'total_dettra' => $totalBefore,
            'nombres_agregados' => $updated,
            'porcentaje_cruzado' => $totalBefore > 0 ? round(($updated / $totalBefore) * 100, 2) : 0,
        ]);
    }

    /**
     * Actualiza DETTRA.nombres con el nombre completo desde BASACT.
     *
     * Concatena las 4 columnas de nombre de BASACT:
     * - 1_nombre_trabajador
     * - 2_nombre_trabajador
     * - 1_apellido_trabajador
     * - 2_apellido_trabajador
     *
     * @return int Cantidad de registros actualizados
     */
    private function addNamesToDettra(CollectionNoticeRun $run): int
    {
        // Usar CONCAT_WS para manejar NULLs automáticamente
        // CONCAT_WS ignora valores NULL y no requiere COALESCE
        $affectedRows = DB::update("
            UPDATE data_source_dettra AS dettra
            SET nombres = TRIM(
                CONCAT_WS(' ',
                    basact.\"1_nombre_trabajador\",
                    basact.\"2_nombre_trabajador\",
                    basact.\"1_apellido_trabajador\",
                    basact.\"2_apellido_trabajador\"
                )
            )
            FROM data_source_basact AS basact
            WHERE dettra.run_id = ?
                AND basact.run_id = ?
                AND dettra.nit = basact.identificacion_trabajador
                AND dettra.nombres IS NULL
        ", [$run->id, $run->id]);

        return $affectedRows;
    }
}
