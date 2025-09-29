<?php

namespace App\Livewire\Recaudo\Comunicados;

use App\DTOs\Recaudo\Comunicados\CollectionRunUploadedFileDto;
use App\DTOs\Recaudo\Comunicados\CreateRunFormDataDto;
use App\Models\CollectionNoticeType;
use App\Services\Recaudo\Comunicados\CollectionRunUploadedFileSanitizer;
use App\Services\Recaudo\Comunicados\CreateCollectionNoticeRunSubmissionHandler;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;
use Throwable;

class CreateRunModal extends Component
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
     * @var array<int, array{path:string, original_name:string, size:int, mime:?string, extension:?string|null}>
     */
    public array $uploadedFiles = [];

    public bool $uploadsReady = false;

    public bool $formReady = false;

    private CreateCollectionNoticeRunSubmissionHandler $submissionHandler;

    private CollectionRunUploadedFileSanitizer $uploadedFileSanitizer;

    public function boot(
        CreateCollectionNoticeRunSubmissionHandler $submissionHandler,
        CollectionRunUploadedFileSanitizer $uploadedFileSanitizer
    ): void {
        $this->submissionHandler = $submissionHandler;
        $this->uploadedFileSanitizer = $uploadedFileSanitizer;
    }

    protected function messages(): array
    {
        return [
            'typeId.required' => 'Selecciona un tipo de comunicado.',
            'typeId.exists' => 'Selecciona un tipo de comunicado válido.',
            'period.required' => 'Debes ingresar el periodo en formato YYYYMM.',
            'period.regex' => 'El periodo debe tener formato YYYYMM.',
        ];
    }

    protected array $validationAttributes = [
        'typeId' => 'tipo de comunicado',
        'period' => 'periodo',
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

        $this->uploadedFiles = [];
        $this->uploadsReady = false;
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
            && $this->uploadsReady
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

    public function updatedOpen(bool $value): void
    {
        if (! $value) {
            $this->reset([
                'typeId',
                'dataSources',
                'uploadedFiles',
                'uploadsReady',
                'periodMode',
                'period',
                'periodReadonly',
                'periodValue',
            ]);
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

        if ($propertyName === 'formReady') {
            $this->skipRender();

            return;
        }

        $this->broadcastFormState();
    }

    #[On('collection-run-uploads::state-updated')]
    public function handleUploadsStateUpdated(array $payload): void
    {
        $files = isset($payload['files']) && is_array($payload['files']) ? $payload['files'] : [];
        $this->uploadedFiles = $this->sanitizeUploadedFilesPayload($files);
        $this->uploadsReady = (bool) ($payload['ready'] ?? false);

        if ($this->uploadsReady) {
            $this->resetErrorBag(['files']);
        }

        $this->broadcastFormState();
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

        return $rules;
    }

    #[On('openCreateRunModal')]
    public function handleOpenCreateRunModal(): void
    {
        $this->reset([
            'typeId',
            'dataSources',
            'uploadedFiles',
            'uploadsReady',
            'periodMode',
            'period',
            'periodReadonly',
            'periodValue',
        ]);
        $this->resetValidation();
        $this->open = true;
        $this->broadcastFormState();
    }

    public function cancel(): void
    {
        $this->reset([
            'open',
            'typeId',
            'dataSources',
            'uploadedFiles',
            'uploadsReady',
            'periodMode',
            'period',
            'periodReadonly',
            'periodValue',
        ]);
        $this->resetValidation();
        $this->broadcastFormState();
    }

    public function submit(): void
    {
        $this->validate();

        if (! $this->uploadsReady || count($this->uploadedFiles) !== count($this->dataSources)) {
            $this->addError('files', __('Debes cargar todos los insumos requeridos antes de generar el trabajo.'));
            $this->broadcastFormState();

            return;
        }

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

        foreach ($this->uploadedFiles as $key => $file) {
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
        $isValid = $this->isFormValid;

        if ($this->formReady !== $isValid) {
            $this->formReady = $isValid;
        }

        $this->dispatch('collection-run-form-state-changed', isValid: $isValid);
    }

    public function preventRender(): void
    {
        $this->skipRender();
    }

    /**
     * @param array<int, mixed> $files
     * @return array<int, array{path:string, original_name:string, size:int, mime:?string, extension:?string|null}>
     */
    private function sanitizeUploadedFilesPayload(array $files): array
    {
        $sanitized = [];

        $allowedIds = \collect($this->dataSources)
            ->map(fn (array $source): int => (int) ($source['id'] ?? 0))
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        foreach ($files as $key => $file) {
            if (! is_array($file)) {
                continue;
            }

            $identifier = (int) $key;

            if (! in_array($identifier, $allowedIds, true)) {
                continue;
            }

            $path = isset($file['path']) && is_string($file['path']) ? trim($file['path']) : '';
            $name = isset($file['original_name']) && is_string($file['original_name']) ? trim($file['original_name']) : '';
            $size = isset($file['size']) ? (int) $file['size'] : 0;

            if ($path === '' || $name === '' || $size <= 0) {
                continue;
            }

            if (str_contains($path, '..') || ! str_starts_with($path, 'completed/')) {
                continue;
            }

            $mime = isset($file['mime']) && is_string($file['mime']) && $file['mime'] !== ''
                ? $file['mime']
                : null;

            $extension = isset($file['extension']) && is_string($file['extension']) && $file['extension'] !== ''
                ? strtolower($file['extension'])
                : null;

            $sanitized[$identifier] = [
                'path' => $path,
                'original_name' => $name,
                'size' => $size,
                'mime' => $mime,
                'extension' => $extension,
            ];
        }

        return $sanitized;
    }
}

