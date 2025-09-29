<?php

namespace App\Services\Uploads;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class ChunkedUploadCleaner
{
    public function __construct(private readonly FilesystemFactory $filesystem)
    {
    }

    public function purgeExpired(int $ttlMinutes): void
    {
        if ($ttlMinutes <= 0) {
            return;
        }

        $threshold = Carbon::now('UTC')->subMinutes($ttlMinutes)->getTimestamp();
        $disk = $this->filesystem->disk('collection_temp');

        foreach ([ChunkedUploadManager::PENDING_DIRECTORY, ChunkedUploadManager::COMPLETED_DIRECTORY] as $group) {
            $this->purgeDirectoryGroup($disk, $group, $threshold);
        }
    }

    private function purgeDirectoryGroup(Filesystem $disk, string $group, int $threshold): void
    {
        $directories = $disk->directories($group);

        foreach ($directories as $directory) {
            $normalized = trim($directory, '/');

            if ($normalized === '' || ! str_starts_with($normalized, $group . '/')) {
                continue;
            }

            $lastModified = $this->resolveLastModifiedTimestamp($disk, $normalized);

            if ($lastModified === null || $lastModified >= $threshold) {
                continue;
            }

            try {
                $disk->deleteDirectory($normalized);
            } catch (Throwable $exception) {
                Log::warning('No fue posible limpiar un directorio temporal de cargas de comunicados.', [
                    'directory' => $normalized,
                    'exception' => $exception,
                ]);
            }
        }
    }

    private function resolveLastModifiedTimestamp(Filesystem $disk, string $directory): ?int
    {
        $timestamps = [];

        $this->pushLastModified($disk, $timestamps, $directory);

        foreach ($disk->files($directory, true) as $file) {
            $this->pushLastModified($disk, $timestamps, $file);
        }

        foreach ($disk->directories($directory, true) as $subDirectory) {
            $this->pushLastModified($disk, $timestamps, $subDirectory);
        }

        if (empty($timestamps)) {
            return null;
        }

        return max($timestamps);
    }

    private function pushLastModified(Filesystem $disk, array &$timestamps, string $path): void
    {
        try {
            $timestamps[] = $disk->lastModified($path);
        } catch (RuntimeException) {
            // Ignorar rutas que no soportan lastModified
        } catch (Throwable $exception) {
            Log::debug('No fue posible leer la fecha de modificación de un archivo temporal.', [
                'path' => $path,
                'exception' => $exception,
            ]);
        }
    }
}
