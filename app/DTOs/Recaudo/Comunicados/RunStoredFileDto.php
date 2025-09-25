<?php

namespace App\DTOs\Recaudo\Comunicados;

final class RunStoredFileDto
{
    public function __construct(
        public readonly int $noticeDataSourceId,
        public readonly string $originalName,
        public readonly string $storedName,
        public readonly string $disk,
        public readonly string $path,
        public readonly int $size,
        public readonly ?string $mime,
        public readonly ?string $ext,
        public readonly ?string $sha256,
        public readonly int $uploadedBy,
    ) {}
}
