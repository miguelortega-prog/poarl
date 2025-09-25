<?php

namespace App\DTOs\Recaudo\Comunicados;

use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * @param array<int, TemporaryUploadedFile> $files  // key: notice_data_source_id
 */
final class CreateCollectionNoticeRunDto
{
    public function __construct(
        public readonly int $collectionNoticeTypeId,
        public readonly string $periodValue,
        public readonly int $requestedById,
        public readonly array $files,
    ) {}
}
