<?php

namespace App\Livewire\Recaudo\Comunicados;

use App\Contracts\Recaudo\Comunicados\CollectionRunFormContext;
use App\DTOs\Recaudo\Comunicados\CollectionRunUploadedFileDto;
use App\Services\Recaudo\Comunicados\CollectionRunChunkManager;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

final class CollectionRunUploadManager extends Component implements CollectionRunFormContext
{
    /**
     * @var array<int, array{id:int, name:string, code:string, extension:?string}>
     */
    public array $dataSources = [];

    /**
     * @var array<int, array{path:string, original_name:string, size:int, mime:?string, extension:?string|null}>
     */
    public array $files = [];

    /**
     * @var array<int, bool>
     */
    public array $uploadingSources = [];

    /**
     * @var array<int, bool>
     */
    public array $staleUploads = [];

    public int $maxFileSize = 0;

    public string $uploadEndpoint = '';

    public bool $ready = false;

    private CollectionRunChunkManager $chunkManager;

    public function boot(
        CollectionRunChunkManager $chunkManager
    ): void {
        $this->chunkManager = $chunkManager;
    }

    /**
     * @param array<int, array{id:int, name:string, code:string, extension:?string}> $dataSources
     * @param array<int, array{path:string, original_name:string, size:int, mime:?string, extension:?string|null}> $initialFiles
     */
    public function mount(array $dataSources = [], int $maxFileSize = 0, array $initialFiles = []): void
    {
        $this->dataSources = array_values($dataSources);
        $this->maxFileSize = $maxFileSize > 0 ? $maxFileSize : 0;
        $this->files = $this->sanitizeUploadedFiles($initialFiles);
        $this->uploadEndpoint = route('recaudo.comunicados.uploads.chunk');

        $this->broadcastFormState();
    }

    #[On('collection-run::chunkUploading')]
    #[On('chunk-uploading')]
    public function handleChunkUploadingEvent(mixed $payload = null): void
    {
        $this->chunkManager->handleUploading($this, $payload);
    }

    #[On('collection-run::chunkUploaded')]
    public function handleCollectionRunChunkUploaded(mixed ...$arguments): void
    {
        $this->chunkManager->handleUploaded($this, ...$arguments);
    }

    #[On('chunk-uploaded')]
    public function handleLegacyChunkUploaded(?array $payload = null): void
    {
        $this->chunkManager->handleLegacyUploadedEvent($this, $payload);
    }

    #[On('collection-run::chunkFailed')]
    #[On('chunk-upload-failed')]
    public function handleChunkFailed(?array $payload = null): void
    {
        $this->chunkManager->handleLifecycleEvent($this, $payload, 'failed');
    }

    #[On('collection-run::chunkUploadCancelled')]
    #[On('chunk-upload-cancelled')]
    public function handleChunkCancelled(?array $payload = null): void
    {
        $this->chunkManager->handleLifecycleEvent($this, $payload, 'cancelled');
    }

    #[On('collection-run::chunkUploadCleared')]
    #[On('chunk-upload-cleared')]
    public function handleChunkCleared(?array $payload = null): void
    {
        $this->chunkManager->handleLifecycleEvent($this, $payload, 'cleared');
    }

    public function render(): View
    {
        return view('livewire.recaudo.comunicados.collection-run-upload-manager');
    }

    public function getFormDataSources(): array
    {
        return $this->dataSources;
    }

    public function getMaximumFileSize(): int
    {
        return $this->maxFileSize;
    }

    public function resetUploadedFile(int $dataSourceId): void
    {
        unset($this->files[$dataSourceId], $this->uploadingSources[$dataSourceId], $this->staleUploads[$dataSourceId]);
        $this->resetValidation(['files.' . $dataSourceId]);

        $this->broadcastFormState();
    }

    public function persistUploadedFile(int $dataSourceId, CollectionRunUploadedFileDto $file): void
    {
        $this->files[$dataSourceId] = $file->toArray();
        unset($this->uploadingSources[$dataSourceId], $this->staleUploads[$dataSourceId]);
        $this->resetValidation(['files.' . $dataSourceId]);

        $this->broadcastFormState();
    }

    public function registerFileError(int $dataSourceId, string $message): void
    {
        $this->addError('files.' . $dataSourceId, $message);
        unset($this->uploadingSources[$dataSourceId]);

        $this->broadcastFormState();
    }

    public function logChunkEvent(string $event, int $dataSourceId, array $context = []): void
    {
        Log::info(
            sprintf('Collection notice upload manager %s', $event),
            array_merge([
                'component' => static::class,
                'data_source_id' => $dataSourceId,
            ], $context),
        );
    }

    public function broadcastFormState(): void
    {
        $allUploaded = $this->allFilesUploaded();

        if ($this->ready !== $allUploaded) {
            $this->ready = $allUploaded;
        }

        $this->dispatch('collection-run-uploads::state-updated', files: $this->files, ready: $this->ready);
    }

    public function markFileUploadInProgress(int $dataSourceId): void
    {
        $this->uploadingSources[$dataSourceId] = true;
        $this->staleUploads[$dataSourceId] = true;
        $this->resetValidation(['files.' . $dataSourceId]);

        $this->broadcastFormState();
    }

    public function clearFileUploadState(int $dataSourceId): void
    {
        unset($this->uploadingSources[$dataSourceId], $this->staleUploads[$dataSourceId]);

        $this->broadcastFormState();
    }

    public function preventRender(): void
    {
        $this->skipRender();
    }

    private function allFilesUploaded(): bool
    {
        if (empty($this->dataSources)) {
            return false;
        }

        foreach ($this->dataSources as $dataSource) {
            $identifier = isset($dataSource['id']) ? (int) $dataSource['id'] : 0;

            if ($identifier <= 0) {
                return false;
            }

            if (! isset($this->files[$identifier]) || ! is_array($this->files[$identifier])) {
                return false;
            }

            if (! empty($this->uploadingSources[$identifier]) || ! empty($this->staleUploads[$identifier])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array{path:string, original_name:string, size:int, mime:?string, extension:?string|null}>
     */
    private function sanitizeUploadedFiles(array $files): array
    {
        $sanitized = [];

        foreach ($files as $key => $file) {
            if (! is_array($file)) {
                continue;
            }

            try {
                $sanitized[(int) $key] = CollectionRunUploadedFileDto::fromArray($file)->toArray();
            } catch (Throwable) {
                continue;
            }
        }

        return $sanitized;
    }
}
