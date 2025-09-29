<?php

namespace App\Services\Recaudo\Comunicados;

use App\Contracts\Recaudo\Comunicados\CollectionRunFormContext;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Throwable;

final class CollectionRunChunkManager
{
    public function __construct(private readonly CollectionRunUploadedFileSanitizer $sanitizer)
    {
    }

    public function handleUploading(CollectionRunFormContext $form, mixed $payload = null): void
    {
        $dataSourceId = $this->extractDataSourceId($payload);

        if ($dataSourceId <= 0) {
            return;
        }

        $form->preventRender();

        $form->markFileUploadInProgress($dataSourceId);
        $form->logChunkEvent('uploading', $dataSourceId, $this->buildPayloadContext($payload));
    }

    public function handleUploaded(CollectionRunFormContext $form, mixed ...$arguments): void
    {
        ['dataSourceId' => $dataSourceId, 'file' => $file] = $this->resolveUploadEventArguments(...$arguments);

        if ($dataSourceId <= 0 || ! is_array($file)) {
            if ($dataSourceId > 0) {
                $form->clearFileUploadState($dataSourceId);
            }

            $form->preventRender();

            return;
        }

        $this->processUploadedArray($form, $dataSourceId, $file);
    }

    public function handleLegacyUploadedEvent(CollectionRunFormContext $form, ?array $payload = null): void
    {
        $payload ??= [];
        $dataSourceId = $this->extractDataSourceId($payload);
        $file = isset($payload['file']) && is_array($payload['file']) ? $payload['file'] : null;

        if ($dataSourceId <= 0 || $file === null) {
            if ($dataSourceId > 0) {
                $form->clearFileUploadState($dataSourceId);
            }

            return;
        }

        $this->processUploadedArray($form, $dataSourceId, $file);
    }

    public function handleLifecycleEvent(CollectionRunFormContext $form, ?array $payload, string $status): void
    {
        $payload ??= [];
        $dataSourceId = $this->extractDataSourceId($payload);

        if ($dataSourceId <= 0) {
            return;
        }

        $message = isset($payload['message']) && is_string($payload['message'])
            ? $payload['message']
            : null;

        $form->resetUploadedFile($dataSourceId);

        if ($status === 'failed' && $message) {
            $form->registerFileError($dataSourceId, $message);
        }

        $context = array_merge(['status' => $status], $this->buildPayloadContext($payload));

        $form->logChunkEvent('upload_' . $status, $dataSourceId, array_filter($context));
        $form->preventRender();
    }

    private function processUploadedArray(CollectionRunFormContext $form, int $dataSourceId, array $file): void
    {
        $hasCompletedPayload = isset($file['path'], $file['original_name'])
            && is_string($file['path'])
            && is_string($file['original_name'])
            && $file['path'] !== ''
            && $file['original_name'] !== '';

        if ($hasCompletedPayload) {
            try {
                $requirement = $this->sanitizer->resolveRequirement($dataSourceId, $form->getFormDataSources());
                $normalized = $this->sanitizer->sanitizeFromArray(
                    $dataSourceId,
                    $file,
                    $requirement,
                    $form->getMaximumFileSize(),
                );
            } catch (RuntimeException $exception) {
                $form->logChunkEvent('uploaded_validation_failed', $dataSourceId, [
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                ]);

                $form->registerFileError($dataSourceId, $exception->getMessage());
                $form->preventRender();

                return;
            }

            $form->persistUploadedFile($dataSourceId, $normalized);
            $form->preventRender();

            return;
        }

        try {
            $uploadedFile = TemporaryUploadedFile::unserializeFromLivewireRequest($file);
        } catch (Throwable $exception) {
            $this->handleUploadedFileException($form, $dataSourceId, 'uploaded_exception', $exception, [
                'payload_keys' => array_keys($file),
            ]);

            $form->clearFileUploadState($dataSourceId);
            $form->preventRender();

            return;
        }

        if (! $uploadedFile instanceof TemporaryUploadedFile) {
            $form->logChunkEvent('uploaded_invalid', $dataSourceId, [
                'payload_keys' => array_keys($file),
            ]);

            $form->clearFileUploadState($dataSourceId);
            $form->preventRender();

            return;
        }

        try {
            $requirement = $this->sanitizer->resolveRequirement($dataSourceId, $form->getFormDataSources());
            $normalizedFile = $this->sanitizer->sanitizeFromTemporaryUpload(
                $uploadedFile,
                $dataSourceId,
                $requirement,
                $form->getMaximumFileSize(),
            );
        } catch (Throwable $exception) {
            $this->handleUploadedFileException(
                $form,
                $dataSourceId,
                'uploaded_store_failed',
                $exception,
                [],
                __('No fue posible almacenar temporalmente el archivo cargado.'),
            );

            $form->clearFileUploadState($dataSourceId);
            $form->preventRender();

            return;
        }

        $form->persistUploadedFile($dataSourceId, $normalizedFile);
        $form->logChunkEvent('uploaded', $dataSourceId, [
            'temporary_filename' => $uploadedFile->getFilename(),
            'filesize' => $uploadedFile->getSize(),
        ]);
        $form->preventRender();
    }

    private function handleUploadedFileException(
        CollectionRunFormContext $form,
        int $dataSourceId,
        string $event,
        Throwable $exception,
        array $context = [],
        ?string $message = null
    ): void {
        $form->logChunkEvent($event, $dataSourceId, array_merge($context, [
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
        ]));

        $form->registerFileError($dataSourceId, $message ?? __('No fue posible procesar el archivo cargado.'));
        $form->clearFileUploadState($dataSourceId);

        report($exception);
    }

    /**
     * @return array{dataSourceId:int, file:array<string, mixed>|null}
     */
    private function resolveUploadEventArguments(mixed ...$arguments): array
    {
        if (count($arguments) === 1 && is_array($arguments[0])) {
            $payload = $arguments[0];

            return [
                'dataSourceId' => $this->extractDataSourceId($payload),
                'file' => isset($payload['file']) && is_array($payload['file']) ? $payload['file'] : null,
            ];
        }

        $first = $arguments[0] ?? null;
        $second = $arguments[1] ?? null;

        return [
            'dataSourceId' => $this->extractDataSourceId($first),
            'file' => is_array($second) ? $second : null,
        ];
    }

    private function extractDataSourceId(mixed $payload): int
    {
        if (is_array($payload) && isset($payload['dataSourceId'])) {
            return (int) $payload['dataSourceId'];
        }

        if (is_numeric($payload)) {
            return (int) $payload;
        }

        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayloadContext(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $context = [];

        if (isset($payload['status']) && is_string($payload['status'])) {
            $context['status'] = $payload['status'];
        }

        if (isset($payload['message']) && is_string($payload['message'])) {
            $context['message'] = $payload['message'];
        }

        return $context;
    }
}
