<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recaudo\Comunicados;

use App\Http\Controllers\Controller;
use App\Models\CollectionNoticeRunFile;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class ReplaceFileController extends Controller
{
    public function __construct(
        private readonly FilesystemFactory $filesystem
    ) {
    }

    public function __invoke(Request $request, CollectionNoticeRunFile $file): JsonResponse
    {
        try {
            $run = $file->run;

            if (!in_array($run->status, ['validation_failed', 'pending'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se pueden reemplazar archivos de comunicados con validación fallida o pendientes.',
                ], 403);
            }

            if ($run->requested_by_id !== auth()->id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permisos para modificar este comunicado.',
                ], 403);
            }

            $validated = $request->validate([
                'temp_path' => 'required|string',
            ]);

            DB::beginTransaction();

            $tempDisk = $this->filesystem->disk('collection_temp');
            $disk = $this->filesystem->disk('collection');
            $tempPath = $validated['temp_path'];

            if (!$tempDisk->exists($tempPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'El archivo temporal no está disponible.',
                ], 404);
            }

            // Obtener metadatos del archivo temporal
            $tempFileSize = $tempDisk->size($tempPath);
            $tempMimeType = $tempDisk->mimeType($tempPath);
            $originalName = basename($tempPath);

            // Eliminar archivo anterior del disco
            if ($disk->exists($file->path)) {
                $disk->delete($file->path);
            }

            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION) ?: '') ?: null;
            $safeBase = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
            $storedName = sprintf(
                '%s_%s%s',
                $safeBase ?: 'insumo',
                now()->format('Ymd_His'),
                $ext ? '.' . $ext : ''
            );

            $dataSourceId = $file->notice_data_source_id;
            $relativeDir = sprintf('collection_notice_runs/%d/%d', $run->id, $dataSourceId);
            $relativePath = $relativeDir . '/' . $storedName;

            $disk->makeDirectory($relativeDir);

            $readStream = $tempDisk->readStream($tempPath);
            if ($readStream === false) {
                throw new \RuntimeException('No se pudo leer el archivo temporal.');
            }

            $disk->put($relativePath, $readStream);
            if (is_resource($readStream)) {
                fclose($readStream);
            }

            $tempDisk->delete($tempPath);

            // Actualizar registro en BD
            $file->update([
                'original_name' => $originalName,
                'stored_name' => $storedName,
                'path' => $relativePath,
                'size' => $tempFileSize,
                'mime' => $tempMimeType,
                'ext' => $ext,
                'uploaded_by' => auth()->id(),
                'updated_at' => now(),
            ]);

            // NO cambiar el estado del run - el usuario debe presionar "Re-validar archivos"
            // Solo limpiar el archivo del estado para que el botón esté habilitado cuando todos estén listos

            DB::commit();

            Log::info('Archivo reemplazado exitosamente', [
                'run_id' => $run->id,
                'file_id' => $file->id,
                'data_source_id' => $dataSourceId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Archivo reemplazado correctamente. Presiona "Re-validar archivos" cuando hayas corregido todos los errores.',
            ]);
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Error al reemplazar archivo', [
                'file_id' => $file->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error al reemplazar el archivo.',
            ], 500);
        }
    }
}
