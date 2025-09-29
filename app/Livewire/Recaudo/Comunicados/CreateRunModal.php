<?php

namespace App\Livewire\Recaudo\Comunicados;

use App\DTOs\Recaudo\Comunicados\CreateCollectionNoticeRunDto;
use App\Models\CollectionNoticeType;
use App\UseCases\Recaudo\Comunicados\CreateCollectionNoticeRunUseCase;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
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
     * @var array<string, mixed>
     */
    public array $files = [];

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
            $this->broadcastFormValidity();

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

        $this->broadcastFormValidity();
    }

    public function getIsFormValidProperty(): bool
    {
        return filled($this->typeId)
            && $this->periodInputIsValid()
            && count($this->dataSources) > 0
            && $this->allFilesSelected()
            && $this->getErrorBag()->isEmpty();
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
            $this->broadcastFormValidity();
        }
    }

    public function updated($propertyName): void
    {
        if ($propertyName === 'typeId') {
            $this->validateOnly('typeId');
            $this->broadcastFormValidity();

            return;
        }

        if ($propertyName === 'period' && $this->periodMode === 'write') {
            $this->periodValue = $this->period;
            $this->validateOnly('period');
            $this->broadcastFormValidity();

            return;
        }

        if (str_starts_with($propertyName, 'files.')) {
            $this->validateOnly($propertyName);
            $this->broadcastFormValidity();

            return;
        }

        $this->broadcastFormValidity();
    }

    #[On('collection-run::chunkUploading')]
    public function handleCollectionRunChunkUploading(int $dataSourceId): void
    {
        if ($dataSourceId <= 0) {
            return;
        }

        $this->resetFileSelection($dataSourceId);

        $this->logChunkActivity('uploading', $dataSourceId);
    }

    #[On('collection-run::chunkUploaded')]
    public function handleCollectionRunChunkUploaded(int $dataSourceId, array $file): void
    {
        if ($dataSourceId <= 0) {
            $this->skipRender();
            return;
        }

        $normalizedChunkUpload = $this->normalizeChunkUploadedFile($file);

        if ($normalizedChunkUpload !== null) {
            $this->storeUploadedFileMetadata($dataSourceId, $normalizedChunkUpload);

            $this->skipRender();

            return;
        }

        try {
            $uploadedFile = TemporaryUploadedFile::unserializeFromLivewireRequest($file);
        } catch (Throwable $exception) {
            $this->logChunkActivity('uploaded_exception', $dataSourceId, [
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'payload_keys' => array_keys($file),
            ]);

            $this->addError('files.' . $dataSourceId, __('No fue posible procesar el archivo cargado.'));

            report($exception);

            $this->skipRender();
            return;
        }

        if (! $uploadedFile instanceof TemporaryUploadedFile) {
            $this->logChunkActivity('uploaded_invalid', $dataSourceId, [
                'payload_keys' => array_keys($file),
            ]);

            $this->skipRender();
            return;
        }

        try {
            $normalized = $this->normalizeTemporaryUpload($uploadedFile, $dataSourceId);
        } catch (Throwable $exception) {
            $this->logChunkActivity('uploaded_store_failed', $dataSourceId, [
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            $this->addError('files.' . $dataSourceId, __('No fue posible almacenar temporalmente el archivo cargado.'));

            report($exception);

            $this->skipRender();
            return;
        }

        // Guarda metadata para validación/backoffice
        $this->storeUploadedFileMetadata($dataSourceId, $normalized);

        $this->logChunkActivity('uploaded', $dataSourceId, [
            'temporary_filename' => $uploadedFile->getFilename(),
            'filesize'           => $uploadedFile->getSize(),
        ]);

        $this->skipRender();
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
            $rules['files.' . $dataSource['id'] . '.original_name'] = ['required', 'string'];
            $rules['files.' . $dataSource['id'] . '.size'] = [
                'required',
                'integer',
                'min:1',
                'max:' . $this->getMaxFileSizeBytes(),
            ];
            $rules['files.' . $dataSource['id'] . '.mime'] = ['nullable', 'string'];
            $rules['files.' . $dataSource['id'] . '.extension'] = [
                'nullable',
                'string',
                function (string $attribute, $value, $fail) use ($extension) {
                    if ($value === null || $value === '') {
                        return;
                    }

                    $value = strtolower((string) $value);
                    $allowed = $this->allowedExtensionsFromRequirement(strtolower($extension));

                    if (! in_array($value, $allowed, true)) {
                        $fail($this->extensionErrorMessage($extension));
                    }
                },
            ];
        }

        return $rules;
    }

    #[On('chunk-uploading')]
    public function handleChunkUploading(?array $payload = null): void
    {
        $payload ??= [];
        $dataSourceId = isset($payload['dataSourceId']) ? (int) $payload['dataSourceId'] : 0;

        if ($dataSourceId <= 0) {
            return;
        }

        $this->resetFileSelection($dataSourceId);
    }

    #[On('chunk-uploaded')]
    public function handleChunkUploaded(?array $payload = null): void
    {
        $payload ??= [];
        $dataSourceId = isset($payload['dataSourceId']) ? (int) $payload['dataSourceId'] : 0;
        $file = $payload['file'] ?? null;

        if ($dataSourceId <= 0 || ! is_array($file)) {
            return;
        }

        $normalized = $this->normalizeChunkUploadedFile($file);

        if ($normalized === null) {
            return;
        }

        $this->storeUploadedFileMetadata($dataSourceId, $normalized);
    }

    #[On('openCreateRunModal')]
    public function handleOpenCreateRunModal(): void
    {
        $this->reset(['typeId', 'dataSources', 'files', 'periodMode', 'period', 'periodReadonly', 'periodValue']);
        $this->resetValidation();
        $this->open = true;
        $this->broadcastFormValidity();
    }

    public function cancel(): void
    {
        $this->reset(['open', 'typeId', 'dataSources', 'files', 'periodMode', 'period', 'periodReadonly', 'periodValue']);
        $this->resetValidation();
        $this->broadcastFormValidity();
    }

    public function submit(): void
    {
        $this->validate();

        $userId = (int) auth()->id();

        $normalizedFiles = [];
        foreach ($this->files as $key => $file) {
            $normalizedFiles[(int) $key] = $file;
        }

        $dto = new CreateCollectionNoticeRunDto(
            collectionNoticeTypeId: (int) $this->typeId,
            periodValue: (string) ($this->periodValue ?: $this->period),
            requestedById: $userId,
            files: $normalizedFiles,
        );

        /** @var CreateCollectionNoticeRunUseCase $useCase */
        $useCase = app(CreateCollectionNoticeRunUseCase::class);

        try {
            $result = $useCase($dto);

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

    protected function allowedExtensionsFromRequirement(string $extension): array
    {
        return match ($extension) {
            'csv' => ['csv', 'txt'],
            'xls' => ['xls'],
            'xlsx' => ['xlsx', 'xls'],
            default => ['csv', 'xls', 'xlsx'],
        };
    }

    protected function extensionErrorMessage(string $extension): string
    {
        return match ($extension) {
            'csv' => __('Formato inválido. Este insumo solo acepta archivos CSV o TXT.'),
            'xls' => __('Formato inválido. Este insumo solo acepta archivos XLS.'),
            'xlsx' => __('Formato inválido. Este insumo solo acepta archivos XLSX o XLS.'),
            default => __('Formato inválido. Este insumo permite archivos CSV, XLS o XLSX.'),
        };
    }

    protected function getMaxFileSizeBytes(): int
    {
        return self::MAX_FILE_SIZE_KB * 1024;
    }

    /**
     * @return array{path: string, original_name: string, size: int, mime:?string, extension:?string}
     */
    protected function normalizeTemporaryUpload(TemporaryUploadedFile $uploadedFile, int $dataSourceId): array
    {
        $originalName = $uploadedFile->getClientOriginalName() ?: $uploadedFile->getFilename();
        $baseName = pathinfo($originalName, PATHINFO_FILENAME) ?: 'insumo';
        $safeBase = Str::slug($baseName);

        if ($safeBase === '') {
            $safeBase = 'insumo_' . $dataSourceId;
        }

        $extension = $uploadedFile->getClientOriginalExtension();

        if (! $extension) {
            $extension = pathinfo($originalName, PATHINFO_EXTENSION) ?: null;
        }

        $extension = $extension ? strtolower((string) $extension) : null;

        $directory = 'completed/' . (string) Str::uuid();
        $storedName = $safeBase . ($extension ? '.' . $extension : '');

        $relativePath = $uploadedFile->storeAs($directory, $storedName, 'collection_temp');

        if (! is_string($relativePath) || $relativePath === '') {
            throw new RuntimeException('No fue posible guardar temporalmente el archivo recibido.');
        }

        $size = (int) $uploadedFile->getSize();

        if ($size <= 0) {
            $size = (int) Storage::disk('collection_temp')->size($relativePath);
        }

        if ($size > $this->getMaxFileSizeBytes()) {
            throw new RuntimeException('El archivo excede el tamaño máximo permitido.');
        }

        return [
            'path' => $relativePath,
            'original_name' => $originalName,
            'size' => $size,
            'mime' => $uploadedFile->getMimeType() ?: null,
            'extension' => $extension,
        ];
    }

    protected function resetFileSelection(int $dataSourceId): void
    {
        $key = (string) $dataSourceId;

        unset($this->files[$key]);

        $this->resetValidation(['files.' . $key]);

        $this->broadcastFormValidity();
    }

    protected function logChunkActivity(string $event, int $dataSourceId, array $context = []): void
    {
        Log::info(
            sprintf('Collection notice chunk %s', $event),
            array_merge([
                'component' => static::class,
                'data_source_id' => $dataSourceId,
            ], $context),
        );
    }

    /**
     * @param array{path: string, original_name: string, size: int, mime: string|null, extension: string|null} file
     */
    private function storeUploadedFileMetadata(int $dataSourceId, array $file): void
    {
        $this->files[(string) $dataSourceId] = $file;

        $this->resetValidation(['files.' . $dataSourceId]);

        $this->broadcastFormValidity();
    }

    private function broadcastFormValidity(): void
    {
        $this->dispatch('collection-run-form-state-changed', isValid: $this->isFormValid);
    }

    /**
     * @param array<string, mixed> $file
     *
     * @return array{path: string, original_name: string, size: int, mime: string|null, extension: string|null}|null
     */
    private function normalizeChunkUploadedFile(array $file): ?array
    {
        $path = isset($file['path']) ? (string) $file['path'] : '';
        $originalName = isset($file['original_name']) ? (string) $file['original_name'] : '';
        $size = isset($file['size']) ? (int) $file['size'] : 0;

        if ($path !== '' && $size <= 0 && Storage::disk('collection_temp')->exists($path)) {
            $size = (int) Storage::disk('collection_temp')->size($path);
        }

        if ($path === '' || $originalName === '' || $size <= 0) {
            return null;
        }

        $mime = isset($file['mime']) && is_string($file['mime']) ? $file['mime'] : null;
        $extension = isset($file['extension']) && is_string($file['extension']) ? strtolower($file['extension']) : null;

        if ($extension !== null && $extension === '') {
            $extension = null;
        }

        return [
            'path' => $path,
            'original_name' => $originalName,
            'size' => $size,
            'mime' => $mime,
            'extension' => $extension,
        ];
    }
}
