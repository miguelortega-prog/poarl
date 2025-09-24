<?php

namespace App\Livewire\Recaudo\Comunicados;

use App\Models\CollectionNoticeType;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class CreateRunModal extends Component
{
    use WithFileUploads;

    public bool $open = false;

    public ?int $typeId = null;

    /**
     * @var array<int, array{id:int, name:string}>
     */
    public array $types = [];

    /**
     * @var array<int, array{id:int, name:string, code:string}>
     */
    public array $dataSources = [];

    /**
     * @var array<string, mixed>
     */
    public array $files = [];

    protected array $messages = [
        'typeId.required' => 'Selecciona un tipo de comunicado.',
        'typeId.exists' => 'Selecciona un tipo de comunicado válido.',
        'files.*.required' => 'Debes adjuntar el archivo correspondiente a este insumo.',
        'files.*.file' => 'Adjunta un archivo válido.',
        'files.*.mimes' => 'Solo se permiten archivos en formato CSV o Excel.',
    ];

    protected array $validationAttributes = [
        'typeId' => 'tipo de comunicado',
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
        $this->resetValidation();
        $this->files = [];

        $this->dataSources = [];

        if (! filled($value)) {
            return;
        }

        $type = CollectionNoticeType::query()
            ->with(['dataSources' => function ($query) {
                $query
                    ->select('notice_data_sources.id', 'notice_data_sources.name', 'notice_data_sources.code')
                    ->orderBy('notice_data_sources.name');
            }])
            ->select('collection_notice_types.id')
            ->find($value);

        $this->dataSources = $type?->dataSources
            ->map(fn ($dataSource) => [
                'id' => $dataSource->id,
                'name' => $dataSource->name,
                'code' => $dataSource->code,
            ])
            ->values()
            ->all() ?? [];
    }

    public function getIsFormValidProperty(): bool
    {
        return filled($this->typeId)
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

            if ($key === '' || ! $file instanceof TemporaryUploadedFile) {
                return false;
            }
        }

        return true;
    }

    public function updatedOpen(bool $value): void
    {
        if (! $value) {
            $this->reset(['typeId', 'dataSources', 'files']);
            $this->resetValidation();
        }
    }

    public function updated($propertyName): void
    {
        if ($propertyName === 'typeId') {
            $this->validateOnly('typeId');

            return;
        }

        if (str_starts_with($propertyName, 'files.')) {
            $this->validateOnly($propertyName);
        }
    }

    protected function rules(): array
    {
        $rules = [
            'typeId' => ['required', 'integer', 'exists:collection_notice_types,id'],
        ];

        foreach ($this->dataSources as $dataSource) {
            $rules['files.' . $dataSource['id']] = ['required', 'file', 'mimes:csv,xls,xlsx'];
        }

        return $rules;
    }

    #[On('openCreateRunModal')]
    public function handleOpenCreateRunModal(): void
    {
        $this->reset(['typeId', 'dataSources', 'files']);
        $this->resetValidation();
        $this->open = true;
    }

    public function cancel(): void
    {
        $this->reset(['open', 'typeId', 'dataSources', 'files']);
        $this->resetValidation();
    }

    public function submit(): void
    {
        $this->validate();
    }

    public function render(): View
    {
        return view('livewire.recaudo.comunicados.create-run-modal');
    }
}
