<?php

namespace App\Livewire\Recaudo\Comunicados;

use App\Models\CollectionNoticeType;
use Carbon\Carbon;
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
            'files.*.file' => 'Adjunta un archivo válido.',
            'files.*.max' => 'El archivo supera el tamaño máximo permitido (10 MB).',
        ];

        foreach ($this->dataSources as $dataSource) {
            if (! isset($dataSource['id'])) {
                continue;
            }

            $extension = strtolower((string) ($dataSource['extension'] ?? ''));
            $messages['files.' . $dataSource['id'] . '.mimes'] = match ($extension) {
                'csv' => 'Formato inválido. Este insumo solo acepta archivos CSV o TXT.',
                'xls' => 'Formato inválido. Este insumo solo acepta archivos XLS.',
                'xlsx' => 'Formato inválido. Este insumo solo acepta archivos XLSX o XLS.',
                default => 'Formato inválido. Este insumo permite archivos CSV, XLS o XLSX.',
            };
        }

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

            if ($key === '' || ! $file instanceof TemporaryUploadedFile) {
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
        }
    }

    public function updated($propertyName): void
    {
        if ($propertyName === 'typeId') {
            $this->validateOnly('typeId');

            return;
        }

        if ($propertyName === 'period' && $this->periodMode === 'write') {
            $this->periodValue = $this->period;
            $this->validateOnly('period');

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
                'file',
                'mimes:' . $this->mimesFromExtension($extension),
                'max:10240',
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
    }

    public function cancel(): void
    {
        $this->reset(['open', 'typeId', 'dataSources', 'files', 'periodMode', 'period', 'periodReadonly', 'periodValue']);
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

    protected function mimesFromExtension(string $extension): string
    {
        return match ($extension) {
            'csv' => 'csv,txt',
            'xls' => 'xls',
            'xlsx' => 'xlsx,xls',
            default => 'csv,xls,xlsx',
        };
    }
}
