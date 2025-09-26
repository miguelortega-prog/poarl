<?php

namespace App\DTOs\Recaudo\Comunicados;

final class CreateCollectionNoticeRunDto
{
    /**
     * @param array<int, array{path:string, original_name:string, size:int, mime:?string, extension:?string}> $files
     */
    public function __construct(
        public readonly int $collectionNoticeTypeId,
        public readonly string $periodValue,
        public readonly int $requestedById,
        public readonly array $files,
    ) {}
}
