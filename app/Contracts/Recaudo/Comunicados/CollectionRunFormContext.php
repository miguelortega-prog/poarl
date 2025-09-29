<?php

namespace App\Contracts\Recaudo\Comunicados;

use App\DTOs\Recaudo\Comunicados\CollectionRunUploadedFileDto;

interface CollectionRunFormContext
{
    /**
     * @return array<int, array{id:int, name:string, code:string, extension:?string}>
     */
    public function getFormDataSources(): array;

    public function getMaximumFileSize(): int;

    public function resetUploadedFile(int $dataSourceId): void;

    public function persistUploadedFile(int $dataSourceId, CollectionRunUploadedFileDto $file): void;

    public function registerFileError(int $dataSourceId, string $message): void;

    public function logChunkEvent(string $event, int $dataSourceId, array $context = []): void;

    public function broadcastFormState(): void;

    public function preventRender(): void;
}
