<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step: Exportar y eliminar registros de DETTRA sin datos de contacto.
 *
 * Este step identifica trabajadores independientes que no tienen correo NI dirección
 * después de buscar en BASACT y PAGAPL (ambos son NULL).
 *
 * Proceso:
 * 1. Identifica registros en DETTRA con correo IS NULL AND direccion IS NULL
 * 2. Los agrega al archivo CSV de excluidos existente (excluidos_{run_id}.csv)
 * 3. Elimina estos registros de DETTRA
 *
 * Motivo de exclusión: "Sin datos de contacto"
 *
 * Estos trabajadores no pueden recibir comunicado porque no tenemos forma de
 * contactarlos (ni por correo electrónico ni por correspondencia física).
 *
 * IMPORTANTE: Este step debe ejecutarse DESPUÉS de buscar correo y dirección
 * en BASACT y PAGAPL (pasos 14 y 15).
 */
final class ExportAndRemoveDettraWithoutContactDataStep implements ProcessingStepInterface
{
    private const MOTIVO_EXCLUSION = 'Sin datos de contacto';

    public function __construct(
        private readonly FilesystemFactory $filesystem
    ) {
    }

    public function getName(): string
    {
        return 'Exportar y eliminar registros sin datos de contacto';
    }

    public function execute(CollectionNoticeRun $run): void
    {
        Log::info('Exportando y eliminando registros sin datos de contacto de DETTRA', ['run_id' => $run->id]);

        // Contar registros sin correo NI dirección
        $totalSinContacto = (int) DB::selectOne("
            SELECT COUNT(*) as count
            FROM data_source_dettra
            WHERE run_id = ?
                AND correo IS NULL
                AND direccion IS NULL
        ", [$run->id])->count;

        if ($totalSinContacto === 0) {
            Log::info('No hay registros sin datos de contacto para exportar', ['run_id' => $run->id]);
            return;
        }

        Log::info('Registros sin datos de contacto encontrados', [
            'run_id' => $run->id,
            'total_sin_contacto' => $totalSinContacto,
        ]);

        // Agregar registros sin contacto al archivo de excluidos
        $this->appendToExcludedFile($run, $totalSinContacto);

        // Eliminar registros sin contacto de DETTRA
        $deleted = $this->removeRecordsWithoutContactData($run);

        Log::info('Registros sin datos de contacto procesados', [
            'run_id' => $run->id,
            'exportados' => $totalSinContacto,
            'eliminados' => $deleted,
        ]);
    }

    /**
     * Agrega registros sin datos de contacto al archivo CSV de excluidos existente.
     *
     * @return void
     */
    private function appendToExcludedFile(CollectionNoticeRun $run, int $totalRecords): void
    {
        $fileName = sprintf('excluidos_%d.csv', $run->id);
        $relativeDir = sprintf('collection_notice_runs/%d/results', $run->id);
        $relativePath = $relativeDir . '/' . $fileName;

        $disk = $this->filesystem->disk('collection');

        // Verificar que el directorio y archivo existan
        if (!$disk->exists($relativeDir)) {
            $disk->makeDirectory($relativeDir);
        }

        $tipoComunicado = $run->type?->name ?? 'Sin tipo';
        $periodo = $run->period ?? '';

        // Si el archivo no existe, crear con encabezados
        if (!$disk->exists($relativePath)) {
            $csvContent = "FECHA_CRUCE;NUMERO_ID_APORTANTE;PERIODO;TIPO_COMUNICADO;VALOR;MOTIVO_EXCLUSION\n";
        } else {
            // Si existe, obtener contenido actual
            $csvContent = $disk->get($relativePath);
        }

        $chunkSize = 5000;
        $offset = 0;
        $processedRows = 0;

        // Procesar en chunks y agregar al contenido
        while ($offset < $totalRecords) {
            $rows = DB::select("
                SELECT
                    TO_CHAR(NOW(), 'DD/MM/YYYY') as fecha_cruce,
                    nit as numero_id_aportante,
                    ? as periodo,
                    ? as tipo_comunicado,
                    0 as valor,
                    ? as motivo_exclusion
                FROM data_source_dettra
                WHERE run_id = ?
                    AND correo IS NULL
                    AND direccion IS NULL
                ORDER BY id
                LIMIT ?
                OFFSET ?
            ", [$periodo, $tipoComunicado, self::MOTIVO_EXCLUSION, $run->id, $chunkSize, $offset]);

            foreach ($rows as $row) {
                $csvContent .= sprintf(
                    "%s;%s;%s;%s;%s;%s\n",
                    $row->fecha_cruce,
                    $row->numero_id_aportante,
                    $row->periodo,
                    $row->tipo_comunicado,
                    $row->valor,
                    $row->motivo_exclusion
                );
                $processedRows++;
            }

            $offset += $chunkSize;
        }

        // Sobrescribir archivo con contenido actualizado
        $disk->put($relativePath, $csvContent);

        Log::info('Registros sin datos de contacto agregados al archivo de excluidos', [
            'run_id' => $run->id,
            'file_name' => $fileName,
            'registros_agregados' => $processedRows,
        ]);
    }

    /**
     * Elimina de DETTRA los registros sin datos de contacto.
     *
     * @return int Cantidad de registros eliminados
     */
    private function removeRecordsWithoutContactData(CollectionNoticeRun $run): int
    {
        $totalBefore = DB::table('data_source_dettra')
            ->where('run_id', $run->id)
            ->count();

        // Eliminar registros sin correo NI dirección
        $deleted = DB::delete("
            DELETE FROM data_source_dettra
            WHERE run_id = ?
                AND correo IS NULL
                AND direccion IS NULL
        ", [$run->id]);

        $totalAfter = DB::table('data_source_dettra')
            ->where('run_id', $run->id)
            ->count();

        Log::info('Registros sin datos de contacto eliminados de DETTRA', [
            'run_id' => $run->id,
            'total_antes' => $totalBefore,
            'total_despues' => $totalAfter,
            'eliminados' => $deleted,
            'porcentaje_eliminado' => $totalBefore > 0 ? round(($deleted / $totalBefore) * 100, 2) : 0,
            'quedan_para_comunicado' => $totalAfter,
        ]);

        if ($totalAfter === 0) {
            Log::warning('DETTRA quedó vacío después de eliminar registros sin datos de contacto', [
                'run_id' => $run->id,
            ]);
        }

        return $deleted;
    }
}
