<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recaudo\Comunicados;

use App\Models\CollectionNoticeRun;
use App\Models\CollectionNoticeRunResultFile;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final readonly class DownloadResultFileController
{
    public function __construct(
        private FilesystemFactory $filesystem
    ) {
    }

    /**
     * Descarga un archivo de resultados de un run.
     *
     * @param int $run
     * @param int $resultFile
     *
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function __invoke(int $run, int $resultFile): BinaryFileResponse
    {
        \Log::info('Solicitud de descarga de archivo de resultados', [
            'run_id' => $run,
            'result_file_id' => $resultFile,
            'user_id' => auth()->id(),
        ]);

        // Buscar el run
        $runModel = CollectionNoticeRun::findOrFail($run);

        // Buscar el archivo de resultados
        $resultFileModel = CollectionNoticeRunResultFile::findOrFail($resultFile);

        // Validar que el archivo pertenece al run
        if ($resultFileModel->collection_notice_run_id !== $runModel->id) {
            \Log::warning('Intento de descarga de archivo que no pertenece al run', [
                'run_id' => $run,
                'result_file_id' => $resultFile,
                'expected_run_id' => $resultFileModel->collection_notice_run_id,
            ]);
            abort(Response::HTTP_NOT_FOUND, 'Archivo no encontrado');
        }

        $disk = $this->filesystem->disk($resultFileModel->disk);

        // Obtener la ruta absoluta del archivo
        $absolutePath = $disk->path($resultFileModel->path);

        \Log::info('Verificando existencia de archivo', [
            'run_id' => $run,
            'result_file_id' => $resultFile,
            'disk' => $resultFileModel->disk,
            'path_relative' => $resultFileModel->path,
            'path_absolute' => $absolutePath,
            'exists_via_disk' => $disk->exists($resultFileModel->path),
            'exists_via_file_exists' => file_exists($absolutePath),
        ]);

        // Validar que el archivo existe en el sistema de archivos
        if (!file_exists($absolutePath)) {
            \Log::error('Archivo de resultados no existe en disco', [
                'run_id' => $run,
                'result_file_id' => $resultFile,
                'disk' => $resultFileModel->disk,
                'path' => $resultFileModel->path,
                'absolute_path' => $absolutePath,
            ]);
            abort(Response::HTTP_NOT_FOUND, 'Archivo no encontrado en el disco');
        }

        // Detectar Content-Type basado en la extensión del archivo
        $extension = strtolower(pathinfo($resultFileModel->file_name, PATHINFO_EXTENSION));
        $contentType = match ($extension) {
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv' => 'text/csv; charset=utf-8',
            'txt' => 'text/plain; charset=utf-8',
            default => 'application/octet-stream',
        };

        \Log::info('Iniciando descarga de archivo de resultados', [
            'run_id' => $run,
            'result_file_id' => $resultFile,
            'file_name' => $resultFileModel->file_name,
            'file_size' => $resultFileModel->size,
            'content_type' => $contentType,
            'absolute_path' => $absolutePath,
        ]);

        // Retornar respuesta de descarga directa
        return response()->download(
            $absolutePath,
            $resultFileModel->file_name,
            [
                'Content-Type' => $contentType,
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]
        );
    }
}
