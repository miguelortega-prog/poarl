<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recaudo\Comunicados;

use App\Http\Controllers\Controller;
use App\Models\CollectionNoticeRunFile;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DeleteCollectionNoticeRunFileController extends Controller
{
    public function __construct(
        private readonly FilesystemFactory $filesystem
    ) {
    }

    public function __invoke(CollectionNoticeRunFile $file): JsonResponse
    {
        try {
            $run = $file->run;

            if (!in_array($run->status, ['validation_failed', 'pending'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se pueden eliminar archivos de comunicados con validación fallida o pendientes.',
                ], 403);
            }

            if ($run->requested_by_id !== auth()->id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permisos para eliminar archivos de este comunicado.',
                ], 403);
            }

            DB::beginTransaction();

            $disk = $this->filesystem->disk($file->disk);
            if ($disk->exists($file->path)) {
                $disk->delete($file->path);
            }

            $dataSourceName = $file->dataSource->name ?? 'Desconocido';
            $dataSourceId = $file->notice_data_source_id;
            $file->delete();

            $run->update([
                'status' => 'pending',
                'failed_at' => null,
                'errors' => null,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => sprintf('Archivo del insumo "%s" eliminado correctamente.', $dataSourceName),
                'data' => [
                    'data_source_id' => $dataSourceId,
                    'data_source_name' => $dataSourceName,
                ],
            ]);
        } catch (Throwable $exception) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error al eliminar el archivo. Por favor intenta de nuevo.',
            ], 500);
        }
    }
}
