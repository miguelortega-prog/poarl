<?php

namespace App\View\Components\Recaudo\Comunicados;

use App\DTOs\Recaudo\Comunicados\DataSourceUploadDto;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\View\Component;

class DataSourceUploader extends Component
{
    private const DEFAULT_ACCEPTED_EXTENSIONS = ['csv', 'txt', 'xls', 'xlsx'];

    public readonly DataSourceUploadDto $dataSource;

    /**
     * @var array<string, mixed>|null
     */
    public readonly ?array $selectedFile;

    public readonly string $uploadUrl;

    public readonly int $chunkSize;

    public readonly int $maxFileSize;

    public readonly string $inputId;

    public readonly string $accept;

    /**
     * @param array<string, mixed>|null $selectedFile
     */
    public function __construct(
        DataSourceUploadDto $dataSource,
        ?array $selectedFile = null,
        string $uploadUrl = '',
        int $chunkSize = 0,
        int $maxFileSize = 0,
        ?array $allowedExtensions = null,
    ) {
        $this->dataSource = $dataSource;
        $this->selectedFile = $this->sanitizeSelectedFile($selectedFile);
        $this->uploadUrl = $uploadUrl;
        $this->chunkSize = $chunkSize;
        $this->maxFileSize = $maxFileSize;
        $this->inputId = 'file-' . $this->dataSource->id;
        $this->accept = $this->buildAcceptAttribute($allowedExtensions);
    }

    public function render(): View|Closure|string
    {
        return view('components.recaudo.comunicados.data-source-uploader');
    }

    /**
     * @param array<string, mixed>|null $file
     *
     * @return array{path:string, original_name:string, size:int, mime:?string, extension:?string}|null
     */
    private function sanitizeSelectedFile(?array $file): ?array
    {
        if ($file === null) {
            return null;
        }

        $path = Arr::get($file, 'path');
        $originalName = Arr::get($file, 'original_name');
        $size = Arr::get($file, 'size');

        if (! is_string($path) || $path === '' || ! is_string($originalName) || $originalName === '') {
            return null;
        }

        $sizeValue = is_numeric($size) ? (int) $size : 0;

        if ($sizeValue <= 0) {
            return null;
        }

        $mime = Arr::get($file, 'mime');
        $extension = Arr::get($file, 'extension');

        return [
            'path' => $path,
            'original_name' => $originalName,
            'size' => $sizeValue,
            'mime' => is_string($mime) ? $mime : null,
            'extension' => is_string($extension) ? Str::lower($extension) : null,
        ];
    }

    private function buildAcceptAttribute(?array $allowedExtensions = null): string
    {
        $extensions = $allowedExtensions !== null
            ? $this->normalizeExtensionArray($allowedExtensions)
            : $this->resolveAllowedExtensions();

        if (empty($extensions)) {
            $extensions = self::DEFAULT_ACCEPTED_EXTENSIONS;
        }

        $uniqueExtensions = [];

        foreach ($extensions as $extension) {
            $normalized = Str::start(Str::lower($extension), '.');

            if (! in_array($normalized, $uniqueExtensions, true)) {
                $uniqueExtensions[] = $normalized;
            }
        }

        return implode(',', $uniqueExtensions);
    }

    /**
     * @param array<int, string> $extensions
     *
     * @return array<int, string>
     */
    private function normalizeExtensionArray(array $extensions): array
    {
        return array_values(array_filter(array_map(static function ($value) {
            if (! is_string($value)) {
                return null;
            }

            $normalized = Str::lower(trim($value));

            return $normalized !== '' ? $normalized : null;
        }, $extensions)));
    }

    /**
     * @return array<int, string>
     */
    private function resolveAllowedExtensions(): array
    {
        $extension = Str::lower((string) ($this->dataSource->extension ?? ''));

        return match ($extension) {
            'csv' => ['csv', 'txt', 'xls', 'xlsx'],
            'xls' => ['xls'],
            'xlsx' => ['xlsx', 'xls'],
            'txt' => ['txt', 'csv'],
            default => self::DEFAULT_ACCEPTED_EXTENSIONS,
        };
    }
}
