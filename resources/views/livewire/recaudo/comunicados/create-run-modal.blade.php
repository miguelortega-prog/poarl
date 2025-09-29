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

                        @livewire('recaudo.comunicados.collection-run-upload-manager', [
                            'dataSources' => $dataSources,
                            'maxFileSize' => $this->maxFileSizeBytes,
                            'initialFiles' => $uploadedFiles,
                        ], key('collection-run-upload-manager-' . ($typeId ?? 'none')))
                    @endif
                </div>
            </form>
        </x-slot>

        <x-slot name="footer">
            @php($isFormValid = $this->isFormValid)

            <div
                class="flex w-full justify-end gap-3"
                x-data="{
                    canSubmit: @js($isFormValid),
                    formReady: $wire.entangle('formReady').live,
                }"
                x-effect="canSubmit = Boolean(formReady)"
                x-on:collection-run-form-state-changed.window="canSubmit = Boolean($event.detail?.isValid); formReady = canSubmit"
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
