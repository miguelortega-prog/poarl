<?php

namespace App\DTOs\Recaudo\Comunicados;

use App\DTOs\Recaudo\Comunicados\CreateCollectionNoticeRunDto;

final class CreateRunFormDataDto
{
    /**
     * @param array<int, CollectionRunUploadedFileDto> $files
     */
    public function __construct(
        public readonly int $collectionNoticeTypeId,
        public readonly string $periodValue,
        public readonly int $requestedById,
        public readonly array $files
    ) {
    }

    public function toUseCaseDto(): CreateCollectionNoticeRunDto
    {
        $normalizedFiles = [];

        foreach ($this->files as $key => $file) {
            if (! $file instanceof CollectionRunUploadedFileDto) {
                continue;
            }

            $normalizedFiles[(int) $key] = $file->toArray();
        }

        return new CreateCollectionNoticeRunDto(
            collectionNoticeTypeId: $this->collectionNoticeTypeId,
            periodValue: $this->periodValue,
            requestedById: $this->requestedById,
            files: $normalizedFiles,
        );
    }
}
