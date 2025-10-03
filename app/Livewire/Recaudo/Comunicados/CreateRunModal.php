<?php

declare(strict_types=1);

namespace App\Livewire\Recaudo\Comunicados;

use App\DTOs\Recaudo\Comunicados\CreateCollectionNoticeRunDto;
use App\Models\CollectionNoticeType;
use App\Services\Uploads\ChunkUploadEventHandler;
use App\Services\Uploads\FileMetadataNormalizer;
use App\UseCases\Recaudo\Comunicados\CreateCollectionNoticeRunUseCase;
use App\ValueObjects\Uploads\ChunkUploadEventPayload;
use App\ValueObjects\Uploads\UploadedFileMetadata;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * Componente Livewire para crear ejecuciones de comunicados de recaudo.
 *
 * Refactorizado para seguir principios SOLID:
 * - Delegación de responsabilidades a servicios especializados
 * - Reducción de acoplamiento con lógica de negocio
 * - Manejo de eventos sin mutación directa de estado (evita re-renderizado)
 */
class CreateRunModal extends Component
{
    public bool $open = false;

    public ?int $typeId = null;

    public ?string $periodMode = null;

    public string $period = '';

    public bool $periodReadonly = false;

    public string $periodValue = '';

    /**
     * @var array<int, array{id:int, name:string}>
     */
    #[Locked]
    public array $types = [];

    /**
     * @var array<int, array{id:int, name:string, code:string, extension:?string}>
     */
    public array $dataSources = [];

    /**
     * Almacena archivos cargados por data source ID.
     *
     * IMPORTANTE: Este array NO debe ser modificado directamente en los event listeners
     * para evitar triggering de re-renderizado. Solo se modifica en:
     * - handleCollectionRunChunkStoredEvent (después de validación exitosa)
     * - handleCollectionRunChunkClearedEvent (cuando usuario elimina archivo)
     *
     * @var array<int, array{path: string, original_name: string, size: int, mime: string|null, extension: string|null}>
     */
    public array $files = [];

    /**
     * Mensajes de validación personalizados.
     *
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
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
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'typeId' => 'tipo de comunicado',
            'period' => 'periodo',
            'files.*' => 'insumo requerido',
        ];
    }

    public function mount(): void
    {
        $this->types = CollectionNoticeType::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->toArray();
    }

    public function updatedTypeId(mixed $value): void
    {
        $this->resetValidation(['typeId', 'period', 'files']);

        $this->files = [];
        $this->dataSources = [];
        $this->periodMode = null;
        $this->period = '';
        $this->periodReadonly = false;
        $this->periodValue = '';

        if (!filled($value)) {
            $this->broadcastFormValidity();

            return;
        }

        $type = CollectionNoticeType::query()
            ->with(['dataSources' => function ($query): void {
                $query
                    ->select('notice_data_sources.id', 'notice_data_sources.name', 'notice_data_sources.code', 'notice_data_sources.extension')
                    ->orderBy('notice_data_sources.name');
            }])
            ->select('collection_notice_types.id', 'collection_notice_types.period')
            ->find($value);

        if ($type === null) {
            return;
        }

        $this->dataSources = $type->dataSources
            ->map(fn ($dataSource): array => [
                'id' => $dataSource->id,
                'name' => $dataSource->name,
                'code' => $dataSource->code,
                'extension' => $dataSource->extension,
            ])
            ->values()
            ->all();

        $this->periodMode = $type->period;

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
        $checks = [
            'typeId_filled' => filled($this->typeId),
            'period_valid' => $this->periodInputIsValid(),
            'has_dataSources' => count($this->dataSources) > 0,
            'all_files_selected' => $this->allFilesSelected(),
            'no_errors' => $this->getErrorBag()->isEmpty(),
        ];

        return filled($this->typeId)
            && $this->periodInputIsValid()
            && count($this->dataSources) > 0
            && $this->allFilesSelected()
            && $this->getErrorBag()->isEmpty();
    }

    public function getMaxFileSizeLabelProperty(): string
    {
        return $this->formatBytes($this->getMaxFileSizeBytes());
    }

    public function getMaxFileSizeBytesProperty(): int
    {
        return $this->getMaxFileSizeBytes();
    }

    protected function allFilesSelected(): bool
    {
        if ($this->dataSources === []) {
            return false;
        }

        foreach ($this->dataSources as $dataSource) {
            $dataSourceId = $dataSource['id'] ?? null;

            if (!is_int($dataSourceId) && !is_numeric($dataSourceId)) {
                return false;
            }

            $key = (int) $dataSourceId;
            $file = $this->files[$key] ?? null;

            if (!is_array($file) || !isset($file['path']) || trim((string) $file['path']) === '') {
                return false;
            }
        }

        return true;
    }

    public function updatedOpen(bool $value): void
    {
        if (!$value) {
            $this->reset(['typeId', 'dataSources', 'files', 'periodMode', 'period', 'periodReadonly', 'periodValue']);
            $this->resetValidation();
            $this->broadcastFormValidity();
        }
    }

    public function updated(string $propertyName): void
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

    /**
     * Maneja el evento cuando el JavaScript inicia la carga de un chunk.
     *
     * CRÍTICO: NO mutamos $this->files aquí para evitar re-renderizado.
     * El JavaScript maneja su propio estado localmente.
     */
    #[On('collection-run::chunkUploading')]
    public function handleCollectionRunChunkUploading(mixed ...$arguments): void
    {
        try {
            $payload = $this->extractPayloadFromArguments($arguments);
            $eventPayload = ChunkUploadEventPayload::fromMixed($payload);

            /** @var ChunkUploadEventHandler $handler */
            $handler = app(ChunkUploadEventHandler::class);
            $handler->handleUploading($eventPayload);

            // Limpiar errores de validación previos para este data source
            $this->resetValidation(['files.' . $eventPayload->dataSourceId]);
        } catch (InvalidArgumentException $e) {
            Log::warning('Payload inválido en chunkUploading', [
                'error' => $e->getMessage(),
                'arguments' => $arguments,
            ]);
        } catch (Throwable $e) {
            Log::error('Error inesperado en chunkUploading', [
                'error' => $e->getMessage(),
                'arguments' => $arguments,
            ]);

            report($e);
        }

        // Prevenir re-renderizado que interrumpe la carga de chunks
        $this->skipRender();
    }

    /**
     * Maneja el evento cuando un chunk se carga exitosamente.
     *
     * Este método SOLO almacena el archivo después de validación exitosa.
     * El JavaScript maneja toda la lógica de UI de progreso.
     */
    #[On('collection-run::chunkStored')]
    public function handleCollectionRunChunkStoredEvent(mixed ...$arguments): void
    {
        try {
            $resolved = $this->resolveUploadEventArguments(...$arguments);
            $dataSourceId = $resolved['dataSourceId'];
            $fileData = $resolved['file'];

            if ($dataSourceId <= 0 || !is_array($fileData)) {
                return;
            }

            /** @var FileMetadataNormalizer $normalizer */
            $normalizer = app(FileMetadataNormalizer::class);

            $metadata = $normalizer->tryNormalize($fileData);

            if ($metadata === null) {
                $this->addError(
                    'files.' . $dataSourceId,
                    __('El archivo cargado tiene un formato inválido.')
                );

                return;
            }

            // AQUÍ sí mutamos $this->files, pero SOLO cuando el archivo está validado y listo
            $this->files[$dataSourceId] = $metadata->toArray();
            $this->resetValidation(['files.' . $dataSourceId]);
            $this->broadcastFormValidity();

            Log::info('Archivo almacenado exitosamente en Livewire', [
                'data_source_id' => $dataSourceId,
                'path' => $metadata->path,
            ]);
        } catch (Throwable $e) {
            Log::error('Error al almacenar archivo en Livewire', [
                'error' => $e->getMessage(),
                'arguments' => $arguments,
            ]);

            report($e);
        }
    }

    /**
     * Maneja el evento cuando el usuario elimina un archivo cargado.
     */
    #[On('collection-run::chunkCleared')]
    public function handleCollectionRunChunkClearedEvent(mixed ...$arguments): void
    {
        try {
            $payload = $this->extractPayloadFromArguments($arguments);
            $eventPayload = ChunkUploadEventPayload::fromMixed($payload);

            /** @var ChunkUploadEventHandler $handler */
            $handler = app(ChunkUploadEventHandler::class);
            $handler->handleCleared($eventPayload);

            // Eliminar archivo del estado de Livewire
            unset($this->files[$eventPayload->dataSourceId]);
            $this->resetValidation(['files.' . $eventPayload->dataSourceId]);
            $this->broadcastFormValidity();
        } catch (Throwable $e) {
            Log::error('Error al limpiar archivo', [
                'error' => $e->getMessage(),
                'arguments' => $arguments,
            ]);

            report($e);
        }
    }

    /**
     * Maneja el evento cuando falla la carga de un chunk.
     */
    #[On('collection-run::chunkFailed')]
    public function handleCollectionRunChunkFailed(mixed ...$arguments): void
    {
        try {
            $payload = $this->extractPayloadFromArguments($arguments);
            $eventPayload = ChunkUploadEventPayload::fromMixed($payload);

            /** @var ChunkUploadEventHandler $handler */
            $handler = app(ChunkUploadEventHandler::class);
            $result = $handler->handleFailed($eventPayload);

            if (isset($result['error']) && is_string($result['error'])) {
                $this->addError('files.' . $eventPayload->dataSourceId, $result['error']);
            }

            $this->broadcastFormValidity();
        } catch (Throwable $e) {
            Log::error('Error al manejar fallo de chunk', [
                'error' => $e->getMessage(),
                'arguments' => $arguments,
            ]);

            report($e);
        }
    }

    /**
     * Extrae el payload de los argumentos del evento.
     *
     * @param array<int, mixed> $arguments
     */
    private function extractPayloadFromArguments(array $arguments): mixed
    {
        if ($arguments === []) {
            return null;
        }

        return $arguments[0] ?? null;
    }

    /**
     * Resuelve argumentos de evento de upload que pueden venir en diferentes formatos.
     *
     * @param array<int, mixed> $arguments
     *
     * @return array{dataSourceId: int, file: array<string, mixed>|null}
     */
    private function resolveUploadEventArguments(mixed ...$arguments): array
    {
        if (count($arguments) === 1 && is_array($arguments[0])) {
            $payload = $arguments[0];

            $dataSourceId = 0;
            if (isset($payload['dataSourceId']) && is_numeric($payload['dataSourceId'])) {
                $dataSourceId = (int) $payload['dataSourceId'];
            }

            $file = null;
            if (isset($payload['file']) && is_array($payload['file'])) {
                $file = $payload['file'];
            }

            return [
                'dataSourceId' => $dataSourceId,
                'file' => $file,
            ];
        }

        $first = $arguments[0] ?? null;
        $second = $arguments[1] ?? null;

        $dataSourceId = 0;
        if (is_numeric($first)) {
            $dataSourceId = (int) $first;
        }

        $file = is_array($second) ? $second : null;

        return [
            'dataSourceId' => $dataSourceId,
            'file' => $file,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = [
            'typeId' => ['required', 'integer', 'exists:collection_notice_types,id'],
        ];

        if ($this->periodMode === 'write') {
            $rules['period'] = [
                'required',
                'regex:/^\d{6}$/',
                function (string $attribute, mixed $value, $fail): void {
                    if (!is_string($value) || !$this->isValidPeriodValue($value)) {
                        $fail(__('El periodo debe tener formato YYYYMM válido.'));
                    }
                },
            ];
        }

        foreach ($this->dataSources as $dataSource) {
            if (!isset($dataSource['id'])) {
                continue;
            }

            $dataSourceId = (int) $dataSource['id'];
            $extension = strtolower((string) ($dataSource['extension'] ?? ''));

            $rules['files.' . $dataSourceId] = ['required', 'array'];
            $rules['files.' . $dataSourceId . '.path'] = [
                'required',
                'string',
                function (string $attribute, mixed $value, $fail): void {
                    if (!is_string($value) || str_contains($value, '..') || !str_starts_with($value, 'completed/')) {
                        $fail(__('La ruta del archivo es inválida.'));
                    }
                },
            ];
            $rules['files.' . $dataSourceId . '.original_name'] = ['required', 'string', 'max:255'];
            $rules['files.' . $dataSourceId . '.size'] = [
                'required',
                'integer',
                'min:1',
                'max:' . $this->getMaxFileSizeBytes(),
            ];
            $rules['files.' . $dataSourceId . '.mime'] = [
                'nullable',
                'string',
                function (string $attribute, mixed $value, $fail) use ($extension): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    if (!is_string($value)) {
                        $fail(__('El tipo de archivo es inválido.'));

                        return;
                    }

                    $allowedMimes = $this->allowedMimesFromRequirement(strtolower($extension));

                    if (!in_array(strtolower($value), $allowedMimes, true)) {
                        $fail(__('El tipo de archivo cargado no está permitido para este insumo.'));
                    }
                },
            ];
            $rules['files.' . $dataSourceId . '.extension'] = [
                'nullable',
                'string',
                function (string $attribute, mixed $value, $fail) use ($extension): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    if (!is_string($value)) {
                        $fail(__('La extensión del archivo es inválida.'));

                        return;
                    }

                    $normalized = strtolower($value);
                    $allowed = $this->allowedExtensionsFromRequirement(strtolower($extension));

                    if (!in_array($normalized, $allowed, true)) {
                        $fail($this->extensionErrorMessage($extension));
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
        Log::info('CreateRunModal::submit iniciado', [
            'typeId' => $this->typeId,
            'periodMode' => $this->periodMode,
            'periodValue' => $this->periodValue,
            'files_keys' => array_keys($this->files),
            'dataSources_count' => count($this->dataSources),
        ]);

        try {
            $this->validate();
        } catch (Throwable $e) {
            Log::error('Error de validación en CreateRunModal', [
                'errors' => $this->getErrorBag()->toArray(),
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }

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
            $useCase($dto);

            $this->dispatch('toast', type: 'success', message: __('Trabajo generado correctamente.'));
            $this->cancel();
            $this->dispatch('collectionNoticeRunCreated');
        } catch (Throwable $e) {
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

    protected function isValidPeriodValue(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{6}$/', $value)) {
            return false;
        }

        $year = (int) substr($value, 0, 4);
        $month = (int) substr($value, 4, 2);

        return $year >= 2000 && $month >= 1 && $month <= 12;
    }

    /**
     * @return list<string>
     */
    protected function allowedExtensionsFromRequirement(string $extension): array
    {
        return match ($extension) {
            'csv' => ['csv', 'txt', 'xls', 'xlsx'],
            'xls' => ['xls'],
            'xlsx' => ['xlsx', 'xls'],
            default => ['csv', 'xls', 'xlsx', 'txt'],
        };
    }

    protected function extensionErrorMessage(string $extension): string
    {
        return match ($extension) {
            'csv' => __('Formato inválido. Este insumo permite archivos CSV, TXT, XLS o XLSX.'),
            'xls' => __('Formato inválido. Este insumo solo acepta archivos XLS.'),
            'xlsx' => __('Formato inválido. Este insumo solo acepta archivos XLSX o XLS.'),
            default => __('Formato inválido. Este insumo permite archivos CSV, XLS, XLSX o TXT.'),
        };
    }

    protected function getMaxFileSizeBytes(): int
    {
        $configured = (int) config('chunked-uploads.collection_notices.max_file_size');

        if ($configured > 0) {
            return $configured;
        }

        return 512 * 1024 * 1024;
    }

    /**
     * @return list<string>
     */
    protected function allowedMimesFromRequirement(string $extension): array
    {
        return match ($extension) {
            'csv' => [
                'text/csv',
                'text/plain',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
            'xls' => ['application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'],
            default => [
                'text/csv',
                'text/plain',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        };
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $index = 0;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index += 1;
        }

        $decimals = $value >= 10 || $index === 0 ? 0 : 1;

        return number_format($value, $decimals, ',', '.') . ' ' . $units[$index];
    }

    private function broadcastFormValidity(): void
    {
        $this->dispatch('collection-run-form-state-changed', isValid: $this->isFormValid);
    }
}