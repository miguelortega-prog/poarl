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
                        <p class="rounded-3xl bg-gray-100 px-4 py-2 text-xs text-gray-600 dark:bg-gray-900/60 dark:text-gray-300">
                            {{ __('Cada archivo puede pesar máximo :size.', ['size' => $this->maxFileSizeLabel]) }}
                        </p>

                        @php($chunkUploadUrl = route('recaudo.comunicados.uploads.chunk', absolute: false))

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
                                    @php($dataSourceDto = \App\DTOs\Recaudo\Comunicados\DataSourceUploadDto::fromArray($dataSource))
                                    @php($errorKey = $dataSourceDto->id > 0 ? 'files.' . $dataSourceDto->id : null)

                                    <x-recaudo.comunicados.data-source-uploader
                                        :wire:key="'data-source-' . ($dataSourceDto->id ?: 'unknown')"
                                        :data-source="$dataSourceDto"
                                        :selected-file="is_array($selectedFile) ? $selectedFile : null"
                                        :upload-url="$chunkUploadUrl"
                                        :chunk-size="(int) config('chunked-uploads.collection_notices.chunk_size')"
                                        :max-file-size="$this->maxFileSizeBytes"
                                    >
                                        @if ($errorKey)
                                            @error($errorKey)
                                                <p class="inline-flex items-center rounded-3xl bg-danger px-3 py-1 text-xs font-semibold text-white">
                                                    {{ $message }}
                                                </p>
                                            @enderror
                                        @endif
                                    </x-recaudo.comunicados.data-source-uploader>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </form>
        </x-slot>

        <x-slot name="footer">
            @php($isFormValid = $this->isFormValid)

            <div
                class="flex w-full justify-end gap-3"
                x-data="{ canSubmit: @js($isFormValid) }"
                x-on:collection-run-form-state-changed.window="canSubmit = Boolean($event.detail?.isValid)"
            >
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
                    :disabled="!canSubmit"
                    :aria-disabled="(!canSubmit).toString()"
                    class="inline-flex items-center justify-center gap-2 rounded-3xl bg-secondary-900 px-5 py-2 text-button font-semibold text-primary transition hover:bg-secondary focus:outline-none focus:ring-2 focus:ring-secondary focus:ring-offset-2 focus:ring-offset-white disabled:cursor-not-allowed disabled:opacity-60 dark:focus:ring-offset-gray-900"
                >
                    {{ __('Generar Trabajo') }}
                </button>
            </div>
        </x-slot>
    </x-dialog-modal>
</div>
