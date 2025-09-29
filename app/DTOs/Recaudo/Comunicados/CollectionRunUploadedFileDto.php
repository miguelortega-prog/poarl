<?php

namespace App\DTOs\Recaudo\Comunicados;

use InvalidArgumentException;

final class CollectionRunUploadedFileDto
{
    public function __construct(
        public readonly string $path,
        public readonly string $originalName,
        public readonly int $size,
        public readonly ?string $mime,
        public readonly ?string $extension
    ) {
        if ($path === '') {
            throw new InvalidArgumentException('La ruta del archivo no puede estar vacía.');
        }

        if ($originalName === '') {
            throw new InvalidArgumentException('El nombre original del archivo no puede estar vacío.');
        }

        if ($size <= 0) {
            throw new InvalidArgumentException('El tamaño del archivo debe ser mayor a cero.');
        }
    }

    /**
     * @param array{path:string, original_name:string, size:int, mime:?string, extension:?string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            path: (string) ($data['path'] ?? ''),
            originalName: (string) ($data['original_name'] ?? ''),
            size: (int) ($data['size'] ?? 0),
            mime: isset($data['mime']) && $data['mime'] !== '' ? (string) $data['mime'] : null,
            extension: isset($data['extension']) && $data['extension'] !== '' ? strtolower((string) $data['extension']) : null,
        );
    }

    /**
     * @return array{path:string, original_name:string, size:int, mime:?string, extension:?string}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'original_name' => $this->originalName,
            'size' => $this->size,
            'mime' => $this->mime,
            'extension' => $this->extension,
        ];
    }
}
