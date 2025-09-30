<?php

declare(strict_types=1);

namespace App\Services\Uploads;

use App\ValueObjects\Uploads\UploadedFileMetadata;
use InvalidArgumentException;
use Throwable;

/**
 * Servicio para normalizar metadata de archivos cargados.
 *
 * Responsabilidades:
 * - Conversión segura de arrays a Value Objects
 * - Manejo de excepciones con contexto
 * - Logging de intentos fallidos
 */
final readonly class FileMetadataNormalizer
{
    /**
     * Normaliza un archivo desde un array.
     *
     * @param array<string, mixed>|null $data
     *
     * @throws InvalidArgumentException
     */
    public function normalize(?array $data): ?UploadedFileMetadata
    {
        if ($data === null || $data === []) {
            return null;
        }

        try {
            return UploadedFileMetadata::fromArray($data);
        } catch (InvalidArgumentException $e) {
            // Re-lanzar con contexto adicional
            throw new InvalidArgumentException(
                sprintf('Error al normalizar metadata del archivo: %s', $e->getMessage()),
                previous: $e
            );
        } catch (Throwable $e) {
            // Capturar cualquier otro error inesperado
            throw new InvalidArgumentException(
                'Error inesperado al procesar la metadata del archivo.',
                previous: $e
            );
        }
    }

    /**
     * Normaliza múltiples archivos indexados por data source ID.
     *
     * @param array<int|string, mixed> $filesData
     *
     * @return array<int, UploadedFileMetadata>
     *
     * @throws InvalidArgumentException
     */
    public function normalizeMany(array $filesData): array
    {
        $normalized = [];

        foreach ($filesData as $dataSourceId => $fileData) {
            if (!is_numeric($dataSourceId)) {
                throw new InvalidArgumentException(sprintf(
                    'El ID del data source debe ser numérico. Recibido: %s',
                    is_scalar($dataSourceId) ? (string) $dataSourceId : gettype($dataSourceId)
                ));
            }

            $id = (int) $dataSourceId;

            if ($id <= 0) {
                throw new InvalidArgumentException(sprintf(
                    'El ID del data source debe ser mayor a 0. Recibido: %d',
                    $id
                ));
            }

            if (!is_array($fileData)) {
                throw new InvalidArgumentException(sprintf(
                    'Los datos del archivo para data source %d deben ser un array.',
                    $id
                ));
            }

            try {
                $metadata = $this->normalize($fileData);

                if ($metadata !== null) {
                    $normalized[$id] = $metadata;
                }
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException(
                    sprintf('Error en archivo para data source %d: %s', $id, $e->getMessage()),
                    previous: $e
                );
            }
        }

        return $normalized;
    }

    /**
     * Intenta normalizar sin lanzar excepciones.
     *
     * @param array<string, mixed>|null $data
     */
    public function tryNormalize(?array $data): ?UploadedFileMetadata
    {
        if ($data === null || $data === []) {
            return null;
        }

        try {
            return $this->normalize($data);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Valida la estructura básica de metadata sin crear el Value Object.
     *
     * @param array<string, mixed>|null $data
     */
    public function isValid(?array $data): bool
    {
        if ($data === null || $data === []) {
            return false;
        }

        try {
            $this->normalize($data);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Extrae el path de forma segura sin validación completa.
     *
     * @param array<string, mixed>|null $data
     *
     * @return non-empty-string|null
     */
    public function extractPath(?array $data): ?string
    {
        if ($data === null || !isset($data['path'])) {
            return null;
        }

        $path = $data['path'];

        if (!is_string($path) || trim($path) === '') {
            return null;
        }

        $trimmed = trim($path);

        // Validación básica de seguridad
        if (str_contains($trimmed, '..') || str_contains($trimmed, "\0")) {
            return null;
        }

        return $trimmed;
    }

    /**
     * Extrae el nombre original de forma segura.
     *
     * @param array<string, mixed>|null $data
     *
     * @return non-empty-string|null
     */
    public function extractOriginalName(?array $data): ?string
    {
        if ($data === null || !isset($data['original_name'])) {
            return null;
        }

        $name = $data['original_name'];

        if (!is_string($name) || trim($name) === '') {
            return null;
        }

        $trimmed = trim($name);

        // Limitar longitud
        if (mb_strlen($trimmed) > 255) {
            return null;
        }

        return $trimmed;
    }

    /**
     * Extrae el tamaño de forma segura.
     *
     * @param array<string, mixed>|null $data
     *
     * @return positive-int|null
     */
    public function extractSize(?array $data): ?int
    {
        if ($data === null || !isset($data['size'])) {
            return null;
        }

        $size = $data['size'];

        if (!is_numeric($size)) {
            return null;
        }

        $intSize = (int) $size;

        if ($intSize <= 0) {
            return null;
        }

        return $intSize;
    }
}