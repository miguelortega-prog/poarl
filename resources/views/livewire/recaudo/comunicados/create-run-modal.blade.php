<div>
    <x-dialog-modal wire:model.live="open" maxWidth="3xl">
        <x-slot name="title">
            {{ __('Nuevo Comunicado') }}
        </x-slot>

        <x-slot name="content">
            <form wire:submit.prevent="submit" id="create-run-form" class="space-y-8">
                <div class="grid grid-cols-2 gap-6">
                    <div class="flex flex-col gap-3">
                        <x-label for="collection_notice_type_id" value="{{ __('Tipo de comunicado') }}" />

                        <select
                            id="collection_notice_type_id"
                            name="collection_notice_type_id"
                            wire:model.live="typeId"
                            class="block w-full rounded-3xl border border-gray-300 bg-white px-4 py-3 text-sm text-gray-700 shadow-sm transition focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                        >
                            <option value="">{{ __('Selecciona un tipo de comunicado') }}</option>
                            @foreach ($types as $type)
                                <option value="{{ $type['id'] }}">{{ $type['name'] }}</option>
                            @endforeach
                        </select>

                        @error('typeId')
                            <p class="inline-flex items-center rounded-3xl bg-danger px-3 py-1 text-xs font-semibold text-white">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <div class="flex flex-col gap-3">
                        <x-label for="period" value="{{ __('Periodo') }}" />

                        <input
                            id="period"
                            name="period"
                            type="text"
                            wire:model.live="period"
                            @if ($periodReadonly) readonly @endif
                            placeholder="{{ $periodMode === 'write' ? __('YYYYMM') : '' }}"
                            class="block w-full rounded-3xl border border-gray-300 bg-white px-4 py-3 text-sm text-gray-700 shadow-sm transition focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                        />

                        @if ($periodMode === 'write')
                            @error('period')
                                <p class="inline-flex items-center rounded-3xl bg-danger px-3 py-1 text-xs font-semibold text-white">
                                    {{ $message }}
                                </p>
                            @enderror
                        @endif
                    </div>
                </div>

                <div class="space-y-4">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                        {{ __('Insumos requeridos') }}
                    </h3>

                    @if (! filled($typeId))
                        <p class="rounded-3xl bg-gray-100 px-4 py-3 text-sm text-gray-600 dark:bg-gray-900/60 dark:text-gray-300">
                            {{ __('Selecciona un tipo de comunicado para ver los insumos requeridos.') }}
                        </p>
                    @elseif (empty($dataSources))
                        <div class="rounded-3xl border border-dashed border-gray-300 px-4 py-4 text-sm text-gray-600 dark:border-gray-600 dark:text-gray-300">
                            {{ __('El tipo de comunicado seleccionado no tiene insumos configurados actualmente.') }}
                        </div>
                    @else
                        <div class="rounded-3xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900/40">
                            <div class="grid grid-cols-12 gap-3 border-b border-gray-200 bg-gray-100 px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                <div class="col-span-12 tablet:col-span-6 desktop:col-span-6">{{ __('Nombre – Código') }}</div>
                                <div class="col-span-12 tablet:col-span-3 desktop:col-span-3">{{ __('Tipo de Archivo') }}</div>
                                <div class="col-span-12 tablet:col-span-3 desktop:col-span-3">{{ __('Upload') }}</div>
                            </div>

                            <div class="max-h-80 overflow-y-auto divide-y divide-gray-200 px-4 dark:divide-gray-700">
                                @foreach ($dataSources as $dataSource)
                                    @php($fileKey = (string) ($dataSource['id'] ?? ''))
                                    @php($selectedFile = $fileKey !== '' ? ($files[$fileKey] ?? null) : null)
                                    <div
                                        wire:key="data-source-{{ $dataSource['id'] }}"
                                        class="grid grid-cols-12 items-start gap-3 py-4 text-sm text-gray-700 dark:text-gray-200"
                                    >
                                        <div class="col-span-12 space-y-1 text-sm tablet:col-span-6 desktop:col-span-6">
                                            <p class="font-semibold text-gray-900 dark:text-gray-100">
                                                {{ $dataSource['name'] }} - {{ $dataSource['code'] }}
                                            </p>
                                        </div>

                                        <div class="col-span-12 text-xs uppercase tracking-wide text-gray-600 dark:text-gray-400 tablet:col-span-3 desktop:col-span-3">
                                            {{ $dataSource['extension'] ? strtoupper($dataSource['extension']) : __('N/A') }}
                                        </div>

                                        <div class="col-span-12 space-y-2 tablet:col-span-3 desktop:col-span-3">
                                            <div
                                                class="space-y-2"
                                                x-data="collectionRunUploader({
                                                    dataSourceId: {{ $dataSource['id'] }},
                                                    uploadUrl: '{{ route('recaudo.comunicados.uploads.chunk') }}',
                                                    initialFile: @js(is_array($selectedFile) ? $selectedFile : null),
                                                })"
                                                x-init="(() => { init(); $el.addEventListener('alpine:destroy', () => destroy(), { once: true }); })()"
                                                :class="{ 'opacity-60': isUploading }"
                                                wire:ignore
                                            >
                                                <label
                                                    for="file-{{ $dataSource['id'] }}"
                                                    class="inline-flex w-full items-center justify-center gap-2 rounded-3xl border border-primary-300 bg-white px-4 py-2 text-sm font-semibold text-primary-900 shadow-sm transition hover:border-primary-500 hover:bg-primary-200/60 focus-within:ring-2 focus-within:ring-primary-500 focus-within:ring-offset-2 focus-within:ring-offset-white dark:border-gray-600 dark:bg-gray-900 dark:text-primary-200 dark:focus-within:ring-offset-gray-900 tablet:w-auto"
                                                    :class="{ 'cursor-not-allowed': isUploading }"
                                                >
                                                    <i class="fa-solid fa-upload"></i>
                                                    <span>{{ __('Seleccionar archivo') }}</span>
                                                    <input
                                                        id="file-{{ $dataSource['id'] }}"
                                                        name="files[{{ $dataSource['id'] }}]"
                                                        type="file"
                                                        x-ref="fileInput"
                                                        @change="handleFileSelected($event)"
                                                        :disabled="isUploading"
                                                        class="sr-only"
                                                    />
                                                </label>

                                                <div x-show="fileName" x-cloak class="space-y-1">
                                                    <div class="flex w-full items-center justify-between gap-2 rounded-3xl bg-gray-100 px-3 py-2 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                                        <span class="max-w-[70%] truncate" x-text="fileName"></span>
                                                        <span class="whitespace-nowrap" x-text="progressLabel()"></span>
                                                    </div>

                                                    <div x-show="isUploading || status === 'completed'" x-cloak class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                                        <div
                                                            class="h-full bg-primary-500 transition-all duration-200 ease-linear"
                                                            :style="`width: ${progress}%`"
                                                        ></div>
                                                    </div>

                                                    <p x-show="status === 'error' && errorMessage" x-cloak class="text-xs font-semibold text-danger" x-text="errorMessage"></p>
                                                </div>
                                            </div>

                                            @error('files.' . $dataSource['id'])
                                                <p class="inline-flex items-center rounded-3xl bg-danger px-3 py-1 text-xs font-semibold text-white">
                                                    {{ $message }}
                                                </p>
                                            @enderror
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </form>
        </x-slot>

        <x-slot name="footer">
            @php($isFormValid = $this->isFormValid)

            <div class="flex w-full justify-end gap-3">
                <button
                    type="button"
                    wire:click="cancel"
                    class="inline-flex items-center justify-center gap-2 rounded-3xl bg-primary-900 px-5 py-2 text-button font-semibold text-secondary transition hover:bg-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-600 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-900"
                >
                    {{ __('Cerrar') }}
                </button>

                <button
                    type="submit"
                    form="create-run-form"
                    @if (! $isFormValid) disabled aria-disabled="true" @else aria-disabled="false" @endif
                    class="inline-flex items-center justify-center gap-2 rounded-3xl bg-secondary-900 px-5 py-2 text-button font-semibold text-primary transition hover:bg-secondary focus:outline-none focus:ring-2 focus:ring-secondary focus:ring-offset-2 focus:ring-offset-white disabled:cursor-not-allowed disabled:opacity-60 dark:focus:ring-offset-gray-900"
                >
                    {{ __('Generar Trabajo') }}
                </button>
            </div>
        </x-slot>
    </x-dialog-modal>
</div>
