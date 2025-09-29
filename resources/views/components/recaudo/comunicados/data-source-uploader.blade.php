@php
    use Illuminate\Support\Str;
@endphp

<div
    {{ $attributes->class(['grid grid-cols-12 items-start gap-3 py-4 text-sm text-gray-700 dark:text-gray-200']) }}
>
    <div class="col-span-12 space-y-1 text-sm tablet:col-span-6 desktop:col-span-6">
        <p class="font-semibold text-gray-900 dark:text-gray-100">
            {{ $dataSource->name }} - {{ $dataSource->code }}
        </p>
    </div>

    <div class="col-span-12 text-xs uppercase tracking-wide text-gray-600 dark:text-gray-400 tablet:col-span-3 desktop:col-span-3">
        {{ $dataSource->extension ? Str::upper($dataSource->extension) : __('N/A') }}
    </div>

    <div class="col-span-12 space-y-2 tablet:col-span-3 desktop:col-span-3">
        <div
            class="space-y-2"
            x-data="collectionRunUploader({
                dataSourceId: {{ $dataSource->id }},
                uploadUrl: @js($uploadUrl),
                chunkSize: @js($chunkSize),
                initialFile: @js($selectedFile),
                maxFileSize: @js($maxFileSize),
            })"
            x-init="(() => { init(); $el.addEventListener('alpine:destroy', () => destroy(), { once: true }); })()"
            :class="{ 'opacity-60': isUploading }"
            wire:ignore
        >
            <label
                for="{{ $inputId }}"
                class="inline-flex w-full items-center justify-center gap-2 rounded-3xl border border-primary-300 bg-white px-4 py-2 text-sm font-semibold text-primary-900 shadow-sm transition hover:border-primary-500 hover:bg-primary-200/60 focus-within:ring-2 focus-within:ring-primary-500 focus-within:ring-offset-2 focus-within:ring-offset-white dark:border-gray-600 dark:bg-gray-900 dark:text-primary-200 dark:focus-within:ring-offset-gray-900 tablet:w-auto"
                :class="{ 'cursor-not-allowed': isUploading }"
            >
                <i class="fa-solid fa-upload"></i>
                <span>{{ __('Seleccionar archivo') }}</span>
                <input
                    id="{{ $inputId }}"
                    name="files[{{ $dataSource->id }}]"
                    type="file"
                    accept="{{ $accept }}"
                    x-ref="fileInput"
                    @change="handleFileSelected($event)"
                    :disabled="isUploading"
                    class="sr-only"
                />
            </label>

            <button
                type="button"
                class="inline-flex w-full items-center justify-center gap-2 rounded-3xl border border-danger bg-white px-4 py-2 text-xs font-semibold text-danger shadow-sm transition hover:bg-danger/10 focus:outline-none focus:ring-2 focus:ring-danger focus:ring-offset-2 focus:ring-offset-white disabled:cursor-not-allowed disabled:opacity-60 dark:border-danger/60 dark:bg-gray-900 dark:text-danger-200 dark:hover:bg-danger/20 dark:focus:ring-offset-gray-900 tablet:w-auto"
                x-show="isUploading || status === 'completed'"
                x-cloak
                @click="cancelUpload()"
                :disabled="isUploading && !currentSession"
            >
                <i :class="isUploading ? 'fa-solid fa-ban' : 'fa-solid fa-xmark'"></i>
                <span x-text="isUploading ? '{{ __('Cancelar carga') }}' : '{{ __('Quitar archivo') }}'"></span>
            </button>

            <div x-show="isUploading" x-cloak class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                <div
                    class="h-full bg-primary-500 transition-all duration-200 ease-linear"
                    :style="`width: ${progress}%`"
                ></div>
            </div>

            <div x-show="status === 'completed'" x-cloak>
                <div class="flex w-full items-center justify-between gap-2 rounded-3xl bg-gray-100 px-3 py-2 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                    <span class="max-w-[70%] truncate" x-text="fileName"></span>
                    <span class="whitespace-nowrap" x-text="progressLabel()"></span>
                </div>
            </div>

            <p x-show="status === 'error' && errorMessage" x-cloak class="text-xs font-semibold text-danger" x-text="errorMessage"></p>
        </div>

        {{ $slot }}
    </div>
</div>
