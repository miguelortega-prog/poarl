<div>
    <x-dialog-modal wire:model.live="open" maxWidth="3xl">
        <x-slot name="title">
            {{ __('Nuevo Comunicado') }}
        </x-slot>

        <x-slot name="content">
            <div class="space-y-8">
                <div class="space-y-3">
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
                        <p class="inline-flex items-center rounded-3xl bg-danger-600 px-3 py-1 text-xs font-semibold text-white">
                            {{ $message }}
                        </p>
                    @enderror
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
                        <div class="space-y-6">
                            @foreach ($dataSources as $dataSource)
                                @php($fileKey = (string) ($dataSource['id'] ?? ''))
                                @php($selectedFile = $fileKey !== '' ? ($files[$fileKey] ?? null) : null)

                                <div wire:key="data-source-{{ $dataSource['id'] }}" class="space-y-2">
                                    <div class="grid items-center gap-4 sm:grid-cols-[minmax(0,1fr)_auto]">
                                        <div class="space-y-1">
                                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                                {{ $dataSource['name'] }}
                                            </p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                                {{ __('Código: :code', ['code' => $dataSource['code']]) }}
                                            </p>
                                        </div>

                                        <div class="flex flex-col items-start gap-2 sm:items-end">
                                            <label
                                                for="file-{{ $dataSource['id'] }}"
                                                class="inline-flex w-full items-center justify-center gap-2 rounded-3xl border border-primary-300 bg-white px-4 py-2 text-sm font-semibold text-primary-900 shadow-sm transition hover:border-primary-500 hover:bg-primary-200/60 focus-within:ring-2 focus-within:ring-primary-500 focus-within:ring-offset-2 focus-within:ring-offset-white dark:border-gray-600 dark:bg-gray-900 dark:text-primary-200 dark:focus-within:ring-offset-gray-900 sm:w-auto"
                                            >
                                                <i class="fa-solid fa-upload"></i>
                                                <span>{{ __('Seleccionar archivo') }}</span>
                                                <input
                                                    id="file-{{ $dataSource['id'] }}"
                                                    name="files[{{ $dataSource['id'] }}]"
                                                    type="file"
                                                    wire:model.live="files.{{ $dataSource['id'] }}"
                                                    accept=".csv,.xlsx,.xls"
                                                    class="sr-only"
                                                />
                                            </label>

                                            @if ($selectedFile)
                                                <p class="max-w-full truncate rounded-3xl bg-gray-100 px-3 py-1 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                                    {{ method_exists($selectedFile, 'getClientOriginalName') ? $selectedFile->getClientOriginalName() : (string) $selectedFile }}
                                                </p>
                                            @endif
                                        </div>
                                    </div>

                                    @error('files.' . $dataSource['id'])
                                        <p class="inline-flex items-center rounded-3xl bg-danger-600 px-3 py-1 text-xs font-semibold text-white">
                                            {{ $message }}
                                        </p>
                                    @enderror
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </x-slot>

        <x-slot name="footer">
            <div class="flex w-full justify-end gap-3">
                <button
                    type="button"
                    wire:click="cancel"
                    class="inline-flex items-center justify-center gap-2 rounded-3xl bg-danger px-5 py-2 text-button font-semibold text-white transition hover:bg-danger-300 focus:outline-none focus:ring-2 focus:ring-danger-300 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-900"
                >
                    {{ __('Cancelar') }}
                </button>

                @php($isFormValid = $this->isFormValid)

                <button
                    type="button"
                    wire:click="submit"
                    @if (! $isFormValid) disabled aria-disabled="true" @else aria-disabled="false" @endif
                    class="inline-flex items-center justify-center gap-2 rounded-3xl bg-primary-900 px-5 py-2 text-button font-semibold text-primary transition hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 focus:ring-offset-white disabled:cursor-not-allowed disabled:opacity-60 dark:focus:ring-offset-gray-900"
                >
                    {{ __('Aceptar') }}
                </button>
            </div>
        </x-slot>
    </x-dialog-modal>
</div>
