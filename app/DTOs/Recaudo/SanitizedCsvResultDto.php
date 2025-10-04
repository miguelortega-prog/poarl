<?php

declare(strict_types=1);

namespace App\DTOs\Recaudo;

final readonly class SanitizedCsvResultDto
{
    public function __construct(
        public string $path,
        public bool $temporary
    ) {
    }
}
