<div>
    <x-dialog-modal wire:model.live="open" maxWidth="3xl">
        <x-slot name="title">
            {{ __('Nuevo Comunicado') }}
        </x-slot>

        <x-slot name="content">
            <div class="space-y-6">
                <div class="space-y-2">
                    <x-label for="collection_notice_type_id" value="{{ __('Tipo de comunicado') }}" />

                    <select
                        id="collection_notice_type_id"
                        name="collection_notice_type_id"
                        wire:model.live="typeId"
                        required
                        class="block w-full rounded-2xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                    >
                        <option value="">{{ __('Selecciona un tipo de comunicado') }}</option>
                        @foreach ($types as $type)
                            <option value="{{ $type['id'] }}">{{ $type['name'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="space-y-4">
                    @if (! filled($typeId))
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('Selecciona un tipo de comunicado para ver los insumos requeridos.') }}
                        </p>
                    @elseif (empty($dataSources))
                        <div class="rounded-2xl border border-dashed border-gray-300 px-4 py-3 text-sm text-gray-500 dark:border-gray-600 dark:text-gray-400">
                            {{ __('El tipo de comunicado seleccionado no tiene insumos configurados actualmente.') }}
                        </div>
                    @else
                        <div class="space-y-5">
                            @foreach ($dataSources as $dataSource)
                                <div wire:key="data-source-{{ $dataSource['id'] }}" class="space-y-2">
                                    <x-label for="file-{{ $dataSource['id'] }}" :value="$dataSource['name']" />

                                    <input
                                        id="file-{{ $dataSource['id'] }}"
                                        name="files[{{ $dataSource['id'] }}]"
                                        type="file"
                                        wire:model.live="files.{{ $dataSource['id'] }}"
                                        accept=".csv,.xlsx,.xls"
                                        required
                                        class="block w-full cursor-pointer rounded-2xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                                    />
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </x-slot>

        <x-slot name="footer">
            <div class="flex w-full justify-end gap-3">
                <x-secondary-button type="button" wire:click="$set('open', false)">
                    {{ __('Cancelar') }}
                </x-secondary-button>

                @php($isFormValid = $this->isFormValid)

                <x-button
                    type="button"
                    class="disabled:cursor-not-allowed disabled:opacity-50"
                    @disabled(! $isFormValid)
                    aria-disabled="{{ $isFormValid ? 'false' : 'true' }}"
                >
                    {{ __('Continuar') }}
                </x-button>
            </div>
        </x-slot>
    </x-dialog-modal>
</div>
