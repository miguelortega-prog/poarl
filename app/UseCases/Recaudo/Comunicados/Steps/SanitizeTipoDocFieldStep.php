<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Sanitizar campo tipo_doc en DETTRA.
 *
 * Este step normaliza los valores del campo tipo_doc para usar
 * los códigos estándar requeridos por el sistema.
 *
 * Mapeo de normalización:
 * - C  → CC (Cédula de Ciudadanía)
 * - E  → CE (Cédula de Extranjería)
 * - F  → PE (Permiso Especial)
 * - T  → TI (Tarjeta de Identidad)
 *
 * Los valores ya normalizados (CC, CE, PE, TI) se mantienen sin cambios.
 *
 * IMPORTANTE: Este step debe ejecutarse antes de generar los consecutivos,
 * ya que el consecutivo incluye el tipo_doc normalizado.
 */
final class SanitizeTipoDocFieldStep implements ProcessingStepInterface
{
    private const TIPO_DOC_MAPPING = [
        'C' => 'CC',  // Cédula de Ciudadanía
        'E' => 'CE',  // Cédula de Extranjería
        'F' => 'PE',  // Permiso Especial
        'T' => 'TI',  // Tarjeta de Identidad
    ];

    public function getName(): string
    {
        return 'Sanitizar campo tipo_doc';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Sanitizando campo tipo_doc en DETTRA', ['run_id' => $run->id]);

        $totalRecords = DB::table('data_source_dettra')
            ->where('run_id', $run->id)
            ->count();

        if ($totalRecords === 0) {
            Log::info('No hay registros en DETTRA para sanitizar tipo_doc', ['run_id' => $run->id]);
            return;
        }

        $totalUpdated = 0;
        $updatesByType = [];

        // Procesar cada mapeo
        foreach (self::TIPO_DOC_MAPPING as $old => $new) {
            $updated = $this->updateTipoDoc($run, $old, $new);
            if ($updated > 0) {
                $updatesByType[$old] = ['to' => $new, 'count' => $updated];
                $totalUpdated += $updated;
            }
        }

        Log::info('Campo tipo_doc sanitizado', [
            'run_id' => $run->id,
            'total_registros' => $totalRecords,
            'total_actualizados' => $totalUpdated,
            'detalle_por_tipo' => $updatesByType,
            'porcentaje_actualizado' => $totalRecords > 0 ? round(($totalUpdated / $totalRecords) * 100, 2) : 0,
        ]);

        // Registrar tipos de documento después de la sanitización
        $this->logTipoDocDistribution($run);
    }

    /**
     * Actualiza tipo_doc de un valor antiguo a uno nuevo.
     *
     * @param CollectionNoticeRun $run
     * @param string $oldValue Valor antiguo (C, E, F, T)
     * @param string $newValue Valor nuevo (CC, CE, PE, TI)
     * @return int Cantidad de registros actualizados
     */
    private function updateTipoDoc(CollectionNoticeRun $run, string $oldValue, string $newValue): int
    {
        $affectedRows = DB::update("
            UPDATE data_source_dettra
            SET tipo_doc = ?
            WHERE run_id = ?
                AND tipo_doc = ?
        ", [$newValue, $run->id, $oldValue]);

        return $affectedRows;
    }

    /**
     * Registra la distribución de tipos de documento después de la sanitización.
     *
     * @return void
     */
    private function logTipoDocDistribution(CollectionNoticeRun $run): void
    {
        $distribution = DB::select("
            SELECT tipo_doc, COUNT(*) as count
            FROM data_source_dettra
            WHERE run_id = ?
            GROUP BY tipo_doc
            ORDER BY count DESC
        ", [$run->id]);

        if (!empty($distribution)) {
            $distributionData = [];
            foreach ($distribution as $row) {
                $distributionData[$row->tipo_doc ?? 'NULL'] = $row->count;
            }

            Log::info('Distribución de tipos de documento después de sanitizar', [
                'run_id' => $run->id,
                'distribucion' => $distributionData,
            ]);
        }
    }
}
