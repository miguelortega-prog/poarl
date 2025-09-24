<?php

namespace App\Livewire\Recaudo\Comunicados;

use App\Models\CollectionNoticeType;
use Illuminate\Contracts\View\View;
use Livewire\Component;
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

    /**
     * @var array<int, string>
     */
    protected $listeners = [
        'openCreateRunModal' => 'handleOpenCreateRunModal',
    ];

    public function mount(): void
    {
        $this->types = CollectionNoticeType::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->toArray();
    }

    public function updatedTypeId(): void
    {
        $this->dataSources = CollectionNoticeType::query()
            ->with(['dataSources' => fn ($query) => $query->orderBy('name')])
            ->find($this->typeId)
            ?->dataSources
            ->map(fn ($dataSource) => $dataSource->only(['id', 'name', 'code']))
            ->values()
            ->all() ?? [];

        $this->files = [];
    }

    public function getIsFormValidProperty(): bool
    {
        return filled($this->typeId)
            && count($this->dataSources) > 0
            && $this->allFilesSelected();
    }

    protected function allFilesSelected(): bool
    {
        if (empty($this->dataSources)) {
            return false;
        }

        foreach ($this->dataSources as $dataSource) {
            $key = (string) ($dataSource['id'] ?? '');

            if ($key === '' || ! array_key_exists($key, $this->files) || blank($this->files[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    public function handleOpenCreateRunModal(): void
    {
        $this->reset(['typeId', 'dataSources', 'files']);
        $this->open = true;
    }

    public function render(): View
    {
        return view('livewire.recaudo.comunicados.create-run-modal');
    }
}
