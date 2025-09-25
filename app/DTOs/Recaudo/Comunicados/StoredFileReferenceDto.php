<?php

namespace App\DTOs\Recaudo\Comunicados;

final class StoredFileReferenceDto
{
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
    ) {
    }
}
