<?php

namespace App\Livewire\Recaudo\Comunicados;

use App\Contracts\Recaudo\Comunicados\CollectionRunFormContext;
use App\DTOs\Recaudo\Comunicados\CollectionRunUploadedFileDto;
use App\DTOs\Recaudo\Comunicados\CreateRunFormDataDto;
use App\Models\CollectionNoticeType;
use App\Services\Recaudo\Comunicados\CollectionRunChunkManager;
use App\Services\Recaudo\Comunicados\CollectionRunUploadedFileSanitizer;
use App\Services\Recaudo\Comunicados\CreateCollectionNoticeRunSubmissionHandler;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;
use Throwable;

class CreateRunModal extends Component implements CollectionRunFormContext
{
    public bool $open = false;

    private const MAX_FILE_SIZE_KB = 512000;

    public ?int $typeId = null;

    public ?string $periodMode = null;

    public string $period = '';

    public bool $periodReadonly = false;

    public string $periodValue = '';

    /**
     * @var array<int, array{id:int, name:string}>
     */
    public array $types = [];

    /**
     * @var array<int, array{id:int, name:string, code:string, extension:?string}>
     */
    public array $dataSources = [];

    /**
     * @var array<string, mixed>
     */
    public array $files = [];

    public bool $formReady = false;

    private CollectionRunChunkManager $chunkManager;

    private CreateCollectionNoticeRunSubmissionHandler $submissionHandler;

    private CollectionRunUploadedFileSanitizer $uploadedFileSanitizer;

    public function boot(
        CollectionRunChunkManager $chunkManager,
        CreateCollectionNoticeRunSubmissionHandler $submissionHandler,
        CollectionRunUploadedFileSanitizer $uploadedFileSanitizer
    ): void {
        $this->chunkManager = $chunkManager;
        $this->submissionHandler = $submissionHandler;
        $this->uploadedFileSanitizer = $uploadedFileSanitizer;
    }

    protected function messages(): array
    {
        $messages = [
            'typeId.required' => 'Selecciona un tipo de comunicado.',
            'typeId.exists' => 'Selecciona un tipo de comunicado válido.',
            'period.required' => 'Debes ingresar el periodo en formato YYYYMM.',
            'period.regex' => 'El periodo debe tener formato YYYYMM.',
            'files.*.required' => 'Debes adjuntar el archivo correspondiente a este insumo.',
            'files.*.array' => 'Adjunta un archivo válido.',
            'files.*.path.required' => 'La carga del archivo aún no finaliza.',
            'files.*.path.string' => 'La ruta temporal del archivo es inválida.',
            'files.*.original_name.required' => 'No se recibió el nombre del archivo cargado.',
            'files.*.original_name.string' => 'El nombre del archivo es inválido.',
            'files.*.size.required' => 'No se detectó el tamaño del archivo cargado.',
            'files.*.size.integer' => 'El tamaño del archivo es inválido.',
            'files.*.size.min' => 'El archivo debe contener información.',
        ];

        return $messages;
    }

    protected array $validationAttributes = [
        'typeId' => 'tipo de comunicado',
        'period' => 'periodo',
        'files.*' => 'insumo requerido',
    ];

    public function mount(): void
    {
        $this->types = CollectionNoticeType::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->toArray();
    }

    public function updatedTypeId($value): void
    {
        $this->resetValidation(['typeId', 'period', 'files']);

        $this->files = [];
        $this->dataSources = [];
        $this->periodMode = null;
        $this->period = '';
        $this->periodReadonly = false;
        $this->periodValue = '';

        if (! filled($value)) {
            $this->broadcastFormState();

            return;
        }

        $type = CollectionNoticeType::query()
            ->with(['dataSources' => function ($query) {
                $query
                    ->select('notice_data_sources.id', 'notice_data_sources.name', 'notice_data_sources.code', 'notice_data_sources.extension')
                    ->orderBy('notice_data_sources.name');
            }])
            ->select('collection_notice_types.id', 'collection_notice_types.period')
            ->find($value);

        $this->dataSources = $type?->dataSources
            ->map(fn ($dataSource) => [
                'id' => $dataSource->id,
                'name' => $dataSource->name,
                'code' => $dataSource->code,
                'extension' => $dataSource->extension,
            ])
            ->values()
            ->all() ?? [];

        $this->periodMode = $type?->period;

        if ($this->periodMode === 'today-2') {
            $this->period = Carbon::now('America/Bogota')->subMonthsNoOverflow(2)->format('Ym');
            $this->periodReadonly = true;
            $this->periodValue = $this->period;
        } elseif ($this->periodMode === 'write') {
            $this->period = '';
            $this->periodReadonly = false;
            $this->periodValue = '';
        } elseif ($this->periodMode === 'all') {
            $this->period = 'Todos Los Periodos';
            $this->periodReadonly = true;
            $this->periodValue = '*';
        } else {
            $this->period = '';
            $this->periodReadonly = false;
            $this->periodValue = '';
        }

        $this->broadcastFormState();
    }

    public function getIsFormValidProperty(): bool
    {
        return filled($this->typeId)
            && $this->periodInputIsValid()
            && count($this->dataSources) > 0
            && $this->allFilesSelected()
            && $this->getErrorBag()->isEmpty();
    }

    public function getMaxFileSizeLabelProperty(): string
    {
        return $this->uploadedFileSanitizer->formatBytes($this->getMaximumFileSize());
    }

    public function getMaxFileSizeBytesProperty(): int
    {
        return $this->getMaximumFileSize();
    }

    protected function allFilesSelected(): bool
    {
        if (empty($this->dataSources)) {
            return false;
        }

        foreach ($this->dataSources as $dataSource) {
            $key = (string) ($dataSource['id'] ?? '');

            $file = $this->files[$key] ?? null;

            if ($key === '' || ! is_array($file) || empty($file['path'])) {
                return false;
            }
        }

        return true;
    }

    public function updatedOpen(bool $value): void
    {
        if (! $value) {
            $this->reset(['typeId', 'dataSources', 'files', 'periodMode', 'period', 'periodReadonly', 'periodValue']);
            $this->resetValidation();
            $this->broadcastFormState();
        }
    }

    public function updated($propertyName): void
    {
        if ($propertyName === 'typeId') {
            $this->validateOnly('typeId');
            $this->broadcastFormState();

            return;
        }

        if ($propertyName === 'period' && $this->periodMode === 'write') {
            $this->periodValue = $this->period;
            $this->validateOnly('period');
            $this->broadcastFormState();

            return;
        }

        if (str_starts_with($propertyName, 'files.')) {
            $this->validateOnly($propertyName);
            $this->broadcastFormState();

            return;
        }

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
    public function handleChunkUploaded(?array $payload = null): void
    {
        $this->chunkManager->handleLegacyUploadedEvent($this, $payload);
    }

    #[On('collection-run::chunkFailed')]
    #[On('chunk-upload-failed')]
    public function handleChunkUploadFailed(?array $payload = null): void
    {
        $this->chunkManager->handleLifecycleEvent($this, $payload, 'failed');
    }

    #[On('collection-run::chunkUploadCancelled')]
    #[On('chunk-upload-cancelled')]
    public function handleChunkUploadCancelled(?array $payload = null): void
    {
        $this->chunkManager->handleLifecycleEvent($this, $payload, 'cancelled');
    }

    #[On('collection-run::chunkUploadCleared')]
    #[On('chunk-upload-cleared')]
    public function handleChunkUploadCleared(?array $payload = null): void
    {
        $this->chunkManager->handleLifecycleEvent($this, $payload, 'cleared');
    }

    protected function rules(): array
    {
        $rules = [
            'typeId' => ['required', 'integer', 'exists:collection_notice_types,id'],
        ];

        if ($this->periodMode === 'write') {
            $rules['period'] = [
                'required',
                'regex:/^\d{6}$/',
                function (string $attribute, $value, $fail) {
                    if (! $this->isValidPeriodValue($value)) {
                        $fail(__('El periodo debe tener formato YYYYMM válido.'));
                    }
                },
            ];
        }

        foreach ($this->dataSources as $dataSource) {
            if (! isset($dataSource['id'])) {
                continue;
            }

            $extension = strtolower((string) ($dataSource['extension'] ?? ''));
            $rules['files.' . $dataSource['id']] = [
                'required',
                'array',
            ];

            $rules['files.' . $dataSource['id'] . '.path'] = [
                'required',
                'string',
                function (string $attribute, $value, $fail) {
                    if (! is_string($value) || str_contains($value, '..') || ! str_starts_with($value, 'completed/')) {
                        $fail(__('La ruta del archivo es inválida.'));
                    }
                },
            ];
            $rules['files.' . $dataSource['id'] . '.original_name'] = ['required', 'string', 'max:255'];
            $rules['files.' . $dataSource['id'] . '.size'] = [
                'required',
                'integer',
                'min:1',
                'max:' . $this->getMaximumFileSize(),
            ];
            $rules['files.' . $dataSource['id'] . '.mime'] = [
                'nullable',
                'string',
                function (string $attribute, $value, $fail) use ($extension) {
                    if ($value === null || $value === '') {
                        return;
                    }

                    $allowedMimes = $this->uploadedFileSanitizer->allowedMimesFromRequirement(strtolower($extension));

                    if (! in_array(strtolower((string) $value), $allowedMimes, true)) {
                        $fail(__('El tipo de archivo cargado no está permitido para este insumo.'));
                    }
                },
            ];
            $rules['files.' . $dataSource['id'] . '.extension'] = [
                'nullable',
                'string',
                function (string $attribute, $value, $fail) use ($extension) {
                    if ($value === null || $value === '') {
                        return;
                    }

                    $value = strtolower((string) $value);
                    $allowed = $this->uploadedFileSanitizer->allowedExtensionsFromRequirement(strtolower($extension));

                    if (! in_array($value, $allowed, true)) {
                        $fail($this->uploadedFileSanitizer->extensionErrorMessage($extension));
                    }
                },
            ];
        }

        return $rules;
    }

    #[On('openCreateRunModal')]
    public function handleOpenCreateRunModal(): void
    {
        $this->reset(['typeId', 'dataSources', 'files', 'periodMode', 'period', 'periodReadonly', 'periodValue']);
        $this->resetValidation();
        $this->open = true;
        $this->broadcastFormState();
    }

    public function cancel(): void
    {
        $this->reset(['open', 'typeId', 'dataSources', 'files', 'periodMode', 'period', 'periodReadonly', 'periodValue']);
        $this->resetValidation();
        $this->broadcastFormState();
    }

    public function submit(): void
    {
        $this->validate();

        $formData = new CreateRunFormDataDto(
            collectionNoticeTypeId: (int) $this->typeId,
            periodValue: (string) ($this->periodValue ?: $this->period),
            requestedById: (int) auth()->id(),
            files: $this->buildUploadedFileDtos(),
        );

        try {
            $this->submissionHandler->handle($formData);

            // UX: cerrar, limpiar y notificar
            $this->dispatch('toast', type: 'success', message: __('Trabajo generado correctamente.'));
            $this->cancel();
            $this->dispatch('collectionNoticeRunCreated');
        } catch (\Throwable $e) {
            report($e);
            $this->addError('general', __('No fue posible crear el trabajo. Intenta de nuevo.'));
            $this->dispatch('toast', type: 'error', message: $e->getMessage());
        }
    }

    /**
     * @return array<int, CollectionRunUploadedFileDto>
     */
    private function buildUploadedFileDtos(): array
    {
        $files = [];

        foreach ($this->files as $key => $file) {
            if (! is_array($file)) {
                continue;
            }

            try {
                $files[(int) $key] = CollectionRunUploadedFileDto::fromArray($file);
            } catch (Throwable $exception) {
                report($exception);

                throw new RuntimeException(__('La información del archivo cargado es inválida.'));
            }
        }

        return $files;
    }

    public function render(): View
    {
        return view('livewire.recaudo.comunicados.create-run-modal');
    }

    protected function periodInputIsValid(): bool
    {
        if ($this->periodMode === 'write') {
            return $this->isValidPeriodValue($this->periodValue ?: $this->period);
        }

        if (in_array($this->periodMode, ['today-2', 'all'], true)) {
            return filled($this->periodValue ?: $this->period);
        }

        return true;
    }

    protected function isValidPeriodValue($value): bool
    {
        if (! is_string($value) || ! preg_match('/^\d{6}$/', $value)) {
            return false;
        }

        $year = (int) substr($value, 0, 4);
        $month = (int) substr($value, 4, 2);

        return $year >= 2000 && $month >= 1 && $month <= 12;
    }

    public function getMaximumFileSize(): int
    {
        $configured = (int) config('chunked-uploads.collection_notices.max_file_size');

        if ($configured > 0) {
            return $configured;
        }

        return self::MAX_FILE_SIZE_KB * 1024;
    }

    public function resetUploadedFile(int $dataSourceId): void
    {
        $key = (string) $dataSourceId;

        unset($this->files[$key]);

        $this->resetValidation(['files.' . $key]);

        $this->broadcastFormState();
    }

    public function logChunkEvent(string $event, int $dataSourceId, array $context = []): void
    {
        Log::info(
            sprintf('Collection notice chunk %s', $event),
            array_merge([
                'component' => static::class,
                'data_source_id' => $dataSourceId,
            ], $context),
        );
    }


    public function broadcastFormState(): void
    {
        $this->formReady = $this->isFormValid;

        $this->dispatch('collection-run-form-state-changed', isValid: $this->formReady);
    }

    public function getFormDataSources(): array
    {
        return $this->dataSources;
    }

    public function persistUploadedFile(int $dataSourceId, CollectionRunUploadedFileDto $file): void
    {
        $this->files[(string) $dataSourceId] = $file->toArray();

        $this->resetValidation(['files.' . $dataSourceId]);

        $this->broadcastFormState();
    }

    public function registerFileError(int $dataSourceId, string $message): void
    {
        $this->addError('files.' . $dataSourceId, $message);

        $this->broadcastFormState();
    }

    public function preventRender(): void
    {
        $this->skipRender();
    }

}
