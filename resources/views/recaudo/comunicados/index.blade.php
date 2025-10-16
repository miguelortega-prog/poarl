<x-app-layout>
    <div class="py-2">
        <div class="max-w-7xl mx-auto space-y-6 sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-3xl p-6 lg:p-8">
                <div class="grid grid-cols-12 items-center gap-4">
                    <h1 class="col-span-12 desktop:col-span-9 text-h3 desktop:text-h2 font-black text-gray-950 dark:text-gray-50">
                        {{ __('Comunicados cartera') }}
                    </h1>

                    <button
                        type="button"
                        x-data="{}"
                        x-on:click.prevent="Livewire.dispatch('openCreateRunModal')"
                        class="col-span-12 desktop:col-span-3 inline-flex w-full items-center justify-center gap-2 rounded-3xl bg-secondary-900 px-5 py-3 text-primary-900 font-semibold text-primary transition hover:bg-secondary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-900 desktop:w-auto desktop:justify-self-end"
                    >
                        <i class="fa-solid fa-plus"></i>
                        <span>{{ __('Nuevo Comunicado') }}</span>
                    </button>
                </div>

                <div class="mt-8 space-y-6">
                    @php
                        $filters = $filters ?? [
                            'requested_by_id' => null,
                            'collection_notice_type_id' => null,
                            'date_from' => null,
                            'date_to' => null,
                        ];
                    @endphp

                    <form
                        method="GET"
                        action="{{ route('recaudo.comunicados.index') }}"
                        class="grid grid-cols-1 gap-4 rounded-3xl border border-gray-200 bg-gray-50 p-6 shadow-inner dark:border-gray-700 dark:bg-gray-900/40 desktop:grid-cols-5"
                    >
                        <div class="grid gap-2">
                            <label for="requested_by_id" class="text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                                {{ __('Usuario programación') }}
                            </label>
                            <select
                                id="requested_by_id"
                                name="requested_by_id"
                                class="w-full rounded-2xl border border-gray-200 bg-white px-4 py-2 text-sm text-gray-700 transition focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900/60 dark:text-gray-100"
                            >
                                <option value="">{{ __('Todos') }}</option>
                                @foreach ($requesters ?? [] as $requester)
                                    <option value="{{ $requester->id }}" @selected((string) $filters['requested_by_id'] === (string) $requester->id)>
                                        {{ $requester->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="grid gap-2">
                            <label for="collection_notice_type_id" class="text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                                {{ __('Tipo de cargue') }}
                            </label>
                            <select
                                id="collection_notice_type_id"
                                name="collection_notice_type_id"
                                class="w-full rounded-2xl border border-gray-200 bg-white px-4 py-2 text-sm text-gray-700 transition focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900/60 dark:text-gray-100"
                            >
                                <option value="">{{ __('Todos') }}</option>
                                @foreach ($types ?? [] as $type)
                                    <option value="{{ $type->id }}" @selected((string) $filters['collection_notice_type_id'] === (string) $type->id)>
                                        {{ $type->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="grid gap-2 desktop:col-span-2">
                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                                {{ __('Rango de fechas') }}
                            </span>
                            <div class="grid grid-cols-1 gap-3 desktop:grid-cols-2">
                                <div class="grid gap-1">
                                    <label for="date_from" class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        {{ __('Desde') }}
                                    </label>
                                    <input
                                        type="date"
                                        id="date_from"
                                        name="date_from"
                                        value="{{ $filters['date_from'] }}"
                                        class="w-full rounded-2xl border border-gray-200 bg-white px-4 py-2 text-sm text-gray-700 transition focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900/60 dark:text-gray-100"
                                    >
                                </div>
                                <div class="grid gap-1">
                                    <label for="date_to" class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        {{ __('Hasta') }}
                                    </label>
                                    <input
                                        type="date"
                                        id="date_to"
                                        name="date_to"
                                        value="{{ $filters['date_to'] }}"
                                        class="w-full rounded-2xl border border-gray-200 bg-white px-4 py-2 text-sm text-gray-700 transition focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900/60 dark:text-gray-100"
                                    >
                                </div>
                            </div>
                        </div>

                        <div class="flex items-end gap-3">
                            <button
                                type="submit"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-3xl bg-primary px-5 py-2 text-sm font-semibold text-white transition hover:bg-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-900"
                            >
                                <i class="fa-solid fa-filter"></i>
                                <span>{{ __('Filtrar') }}</span>
                            </button>

                            <a
                                href="{{ route('recaudo.comunicados.index') }}"
                                class="inline-flex items-center justify-center gap-2 rounded-3xl border border-gray-200 px-5 py-2 text-sm font-semibold text-gray-600 transition hover:border-primary hover:text-primary focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 focus:ring-offset-white dark:border-gray-700 dark:text-gray-300 dark:hover:border-primary"
                            >
                                <i class="fa-solid fa-rotate-right"></i>
                                <span>{{ __('Limpiar') }}</span>
                            </a>
                        </div>
                    </form>

                    <div class="grid grid-cols-8 gap-4 rounded-2xl bg-gray-100 px-6 py-3 text-label font-semibold uppercase tracking-wide text-gray-700 dark:bg-gray-900/60 dark:text-gray-200">
                        <span>{{ __('Id Comunicado') }}</span>
                        <span>{{ __('Tipo De Comunicado') }}</span>
                        <span>{{ __('Periodo') }}</span>
                        <span>{{ __('Fecha Programación') }}</span>
                        <span>{{ __('Usuario Programación') }}</span>
                        <span>{{ __('Estado') }}</span>
                        <span>{{ __('Resultados') }}</span>
                        <span class="text-center">OPERATIONS</span>
                    </div>

                    @forelse ($runs ?? [] as $run)
                        @php
                            $canDelete = in_array($run->status, [
                                \App\Enums\Recaudo\CollectionNoticeRunStatus::PENDING->value,
                                \App\Enums\Recaudo\CollectionNoticeRunStatus::VALIDATION_FAILED->value,
                                \App\Enums\Recaudo\CollectionNoticeRunStatus::FAILED->value,
                                \App\Enums\Recaudo\CollectionNoticeRunStatus::CANCELLED->value,
                            ], true);
                        @endphp
                        <div x-data="{ openRunDetails: false, openResultFiles: false, openErrors: false }">
                        <div class="grid grid-cols-8 items-center gap-4 rounded-2xl border border-gray-200 px-6 py-4 text-body text-gray-700 transition dark:border-gray-700 dark:text-gray-200">
                            <button
                                type="button"
                                @click="openRunDetails = true"
                                class="font-semibold text-primary hover:text-primary-600 hover:underline text-left"
                            >
                                {{ $run->id }}
                            </button>
                            <span class="text-sm">
                                {{ $run->type?->name ?? __('N/D') }}
                            </span>
                            <span class="text-sm">
                                {{ $run->period === '*' ? __('Todos') : ($run->period ?? __('N/D')) }}
                            </span>
                            <span>{{ optional($run->created_at)->format('d/m/Y H:i') ?? __('Sin programación') }}</span>
                            <span>{{ $run->requestedBy?->name ?? __('No asignado') }}</span>
                            <span>
                                @php
                                    $statusEnum = \App\Enums\Recaudo\CollectionNoticeRunStatus::tryFrom($run->status);
                                @endphp
                                @if($statusEnum)
                                    <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold {{ $statusEnum->badgeClass() }}">
                                        {{ $statusEnum->label() }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-gray-500 px-3 py-1 text-xs font-semibold text-white">
                                        {{ __($run->status) }}
                                    </span>
                                @endif
                            </span>
                            <div class="flex justify-center">
                                @if($run->resultFiles->isNotEmpty())
                                    <div class="relative inline-block">
                                        <button
                                            type="button"
                                            @click="openResultFiles = true"
                                            class="inline-flex items-center justify-center rounded-full bg-green-500/10 p-2 text-green-600 transition hover:bg-green-500/20 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-900"
                                            aria-label="{{ __('Ver archivos de resultados') }}"
                                            title="{{ trans_choice(':count archivo resultado|:count archivos resultados', $run->resultFiles->count()) }}"
                                        >
                                            <i class="fa-solid fa-folder text-lg"></i>
                                        </button>
                                        <span class="absolute top-0 right-0 flex h-4 w-4 items-center justify-center rounded-full bg-green-600 text-[10px] font-bold text-white transform translate-x-1/4 -translate-y-1/4">{{ $run->resultFiles->count() }}</span>
                                    </div>
                                @else
                                    <span class="text-xs text-gray-400">{{ __('—') }}</span>
                                @endif
                            </div>

                            {{-- Columna Operations: Ver Errores + Eliminar --}}
                            <div class="flex justify-center gap-2">
                                {{-- Botón Ver Errores --}}
                                @if(in_array($run->status, ['validation_failed', 'failed']))
                                    <button
                                        type="button"
                                        x-on:click="openErrors = true"
                                        class="inline-flex items-center justify-center rounded-full bg-red-500/10 p-2 text-red-600 transition hover:bg-red-500/20 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-900"
                                        aria-label="{{ __('Ver errores de validación') }}"
                                    >
                                        <i class="fa-solid fa-exclamation-triangle text-lg"></i>
                                    </button>
                                @endif

                                {{-- Botón Eliminar --}}
                                <form
                                    method="POST"
                                    action="{{ route('recaudo.comunicados.destroy', $run) }}"
                                    x-data="{ confirmDelete() { return confirm(@js(__('¿Deseas eliminar este comunicado? Esta acción no se puede deshacer.'))); } }"
                                >
                                    @csrf
                                    @method('DELETE')

                                    <button
                                        type="submit"
                                        @click.prevent="
                                            if (! {{ $canDelete ? 'true' : 'false' }}) {
                                                return;
                                            }

                                            if (confirmDelete()) {
                                                $el.closest('form').submit();
                                            }
                                        "
                                        @disabled(! $canDelete)
                                        aria-disabled="{{ $canDelete ? 'false' : 'true' }}"
                                        class="inline-flex items-center justify-center rounded-full p-2 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-900 {{
                                            $canDelete
                                                ? 'bg-red-100 text-red-600 hover:bg-red-200 focus:ring-red-500'
                                                : 'cursor-not-allowed bg-gray-200 text-gray-400 dark:bg-gray-700 dark:text-gray-500'
                                        }}"
                                        title="{{ $canDelete ? __('Eliminar comunicado') : __('Solo se pueden eliminar comunicados en estado: Pendiente, Validación fallida, Fallido o Cancelado.') }}"
                                    >
                                        <i class="fa-solid fa-trash"></i>
                                        <span class="sr-only">{{ __('Eliminar comunicado') }}</span>
                                    </button>
                                </form>
                            </div>
                        </div>

                        {{-- TODOS LOS MODALES FUERA DEL GRID --}}
                        {{-- Modal Detalles del Comunicado --}}
                        <div
                            x-cloak
                            x-show="openRunDetails"
                            x-transition.opacity
                                    class="fixed inset-0 z-50 flex items-center justify-center"
                                    aria-modal="true"
                                    role="dialog"
                                >
                                    <div class="absolute inset-0 bg-gray-900/50" x-on:click="openRunDetails = false"></div>

                                    <div class="relative z-10 w-full max-w-3xl rounded-3xl bg-white p-6 shadow-2xl dark:bg-gray-800">
                                        <div class="flex items-center justify-between">
                                            <h3 class="text-h5 font-semibold text-gray-900 dark:text-gray-100">
                                                {{ __('Detalles del Comunicado #:id', ['id' => $run->id]) }}
                                            </h3>
                                            <button
                                                type="button"
                                                class="rounded-full p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-primary-500"
                                                x-on:click="openRunDetails = false"
                                                aria-label="{{ __('Cerrar') }}"
                                            >
                                                <i class="fa-solid fa-xmark"></i>
                                            </button>
                                        </div>

                                        {{-- Resumen del Run --}}
                                        <div class="mt-6 grid grid-cols-2 gap-4">
                                            <div class="rounded-2xl border border-gray-200 p-4 dark:border-gray-700">
                                                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('Tipo') }}</p>
                                                <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $run->type?->name ?? __('N/D') }}</p>
                                            </div>
                                            <div class="rounded-2xl border border-gray-200 p-4 dark:border-gray-700">
                                                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('Periodo') }}</p>
                                                <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $run->period === '*' ? __('Todos') : ($run->period ?? __('N/D')) }}</p>
                                            </div>
                                            <div class="rounded-2xl border border-gray-200 p-4 dark:border-gray-700">
                                                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('Estado') }}</p>
                                                <p class="mt-1">
                                                    @php
                                                        $statusEnum = \App\Enums\Recaudo\CollectionNoticeRunStatus::tryFrom($run->status);
                                                    @endphp
                                                    @if($statusEnum)
                                                        <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold {{ $statusEnum->badgeClass() }}">
                                                            {{ $statusEnum->label() }}
                                                        </span>
                                                    @else
                                                        <span class="text-sm text-gray-900 dark:text-gray-100">{{ __($run->status) }}</span>
                                                    @endif
                                                </p>
                                            </div>
                                            <div class="rounded-2xl border border-gray-200 p-4 dark:border-gray-700">
                                                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('Solicitado por') }}</p>
                                                <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $run->requestedBy?->name ?? __('No asignado') }}</p>
                                            </div>
                                            <div class="rounded-2xl border border-gray-200 p-4 dark:border-gray-700">
                                                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('Fecha de creación') }}</p>
                                                <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ optional($run->created_at)->format('d/m/Y H:i') ?? __('N/D') }}</p>
                                            </div>
                                            <div class="rounded-2xl border border-gray-200 p-4 dark:border-gray-700">
                                                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('Fecha de ejecución') }}</p>
                                                <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">
                                                    @php
                                                        $completedStatuses = ['completed', 'failed', 'cancelled'];
                                                        $executionDate = in_array($run->status, $completedStatuses) ? $run->updated_at : null;
                                                    @endphp
                                                    {{ optional($executionDate)->format('d/m/Y H:i') ?? __('N/D') }}
                                                </p>
                                            </div>
                                            <div class="rounded-2xl border border-gray-200 p-4 dark:border-gray-700">
                                                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('Duración (minutos)') }}</p>
                                                <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">
                                                    @php
                                                        $completedStatuses = ['completed', 'failed', 'cancelled'];
                                                        if (in_array($run->status, $completedStatuses) && $run->created_at && $run->updated_at) {
                                                            $durationMinutes = (int) ceil($run->created_at->diffInMinutes($run->updated_at));
                                                        } else {
                                                            $durationMinutes = null;
                                                        }
                                                    @endphp
                                                    {{ $durationMinutes ?? __('N/D') }} {{ $durationMinutes ? __('min') : '' }}
                                                </p>
                                            </div>
                                        </div>

                                        {{-- Archivos de Insumo --}}
                                        <div class="mt-6">
                                            <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">
                                                <i class="fa-solid fa-folder-open mr-2 text-primary"></i>
                                                {{ __('Archivos de Insumo') }}
                                            </h4>
                                            <div class="max-h-48 space-y-2 overflow-y-auto pr-1">
                                                @forelse ($run->files as $file)
                                                    <div class="rounded-2xl border border-gray-200 p-3 dark:border-gray-700">
                                                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $file->original_name }}</p>
                                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                                            {{ $file->dataSource?->name ?? __('Sin origen') }}
                                                            •
                                                            {{ $file->uploader?->name ?? __('Sin usuario') }}
                                                            •
                                                            {{ optional($file->created_at)->format('d/m/Y H:i') ?? __('Sin fecha') }}
                                                        </p>
                                                    </div>
                                                @empty
                                                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No hay archivos asignados a este comunicado.') }}</p>
                                                @endforelse
                                            </div>
                                        </div>

                                        <div class="mt-6 flex justify-end">
                                            <button
                                                type="button"
                                                class="inline-flex items-center rounded-full bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-800"
                                                x-on:click="openRunDetails = false"
                                            >
                                                {{ __('Cerrar') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>

                            {{-- Modal Archivos de Resultados --}}
                                <div
                                    x-cloak
                                    x-show="openResultFiles"
                                    x-transition.opacity
                                    class="fixed inset-0 z-50 flex items-center justify-center"
                                    aria-modal="true"
                                    role="dialog"
                                >
                                    <div class="absolute inset-0 bg-gray-900/50" x-on:click="openResultFiles = false"></div>

                                    <div class="relative z-10 w-full max-w-2xl rounded-3xl bg-white p-6 shadow-2xl dark:bg-gray-800">
                                        <div class="flex items-center justify-between">
                                            <h3 class="text-h5 font-semibold text-gray-900 dark:text-gray-100">
                                                <i class="fa-solid fa-folder-open mr-2 text-green-600"></i>
                                                {{ __('Archivos de Resultados') }}
                                            </h3>
                                            <button
                                                type="button"
                                                class="rounded-full p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-green-500"
                                                x-on:click="openResultFiles = false"
                                                aria-label="{{ __('Cerrar') }}"
                                            >
                                                <i class="fa-solid fa-xmark"></i>
                                            </button>
                                        </div>

                                        <div class="mt-6">
                                            <div class="max-h-96 space-y-3 overflow-y-auto pr-1">
                                                @forelse ($run->resultFiles as $resultFile)
                                                    <div class="rounded-2xl border border-gray-200 p-4 hover:border-green-300 hover:bg-green-50/30 dark:border-gray-700 dark:hover:border-green-800 dark:hover:bg-green-900/10 transition">
                                                        <div class="flex items-start justify-between gap-4">
                                                            <div class="flex-1 min-w-0">
                                                                <div class="flex items-center gap-2">
                                                                    @php
                                                                        $extension = strtolower(pathinfo($resultFile->file_name, PATHINFO_EXTENSION));
                                                                        $iconClass = match($extension) {
                                                                            'xls', 'xlsx' => 'fa-file-excel text-green-600',
                                                                            'csv' => 'fa-file-csv text-blue-600',
                                                                            'pdf' => 'fa-file-pdf text-red-600',
                                                                            default => 'fa-file text-gray-600'
                                                                        };
                                                                    @endphp
                                                                    <i class="fa-solid {{ $iconClass }}"></i>
                                                                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate">
                                                                        {{ $resultFile->file_name }}
                                                                    </p>
                                                                </div>
                                                                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                                                    <span class="inline-flex items-center gap-1">
                                                                        <i class="fa-solid fa-tag"></i>
                                                                        {{ ucfirst(str_replace('_', ' ', $resultFile->file_type)) }}
                                                                    </span>
                                                                    <span class="inline-flex items-center gap-1">
                                                                        <i class="fa-solid fa-database"></i>
                                                                        {{ number_format($resultFile->records_count) }} {{ __('registros') }}
                                                                    </span>
                                                                    <span class="inline-flex items-center gap-1">
                                                                        <i class="fa-solid fa-weight-hanging"></i>
                                                                        {{ number_format($resultFile->size / 1024, 2) }} KB
                                                                    </span>
                                                                </div>
                                                            </div>
                                                            <a
                                                                href="{{ route('recaudo.comunicados.download-result', ['run' => $run->id, 'resultFile' => $resultFile->id]) }}"
                                                                class="flex-shrink-0 inline-flex items-center justify-center rounded-full bg-green-600 p-2 text-white transition hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2"
                                                                title="{{ __('Descargar') }}"
                                                            >
                                                                <i class="fa-solid fa-download"></i>
                                                            </a>
                                                        </div>
                                                    </div>
                                                @empty
                                                    <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-8">
                                                        {{ __('No hay archivos de resultados disponibles.') }}
                                                    </p>
                                                @endforelse
                                            </div>
                                        </div>

                                        <div class="mt-6 flex justify-end">
                                            <button
                                                type="button"
                                                class="inline-flex items-center rounded-full bg-gray-200 dark:bg-gray-700 px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-300 transition hover:bg-gray-300 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2"
                                                x-on:click="openResultFiles = false"
                                            >
                                                {{ __('Cerrar') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>

                            {{-- Modal Ver Errores --}}
                            <div
                                x-cloak
                                x-show="openErrors"
                                x-transition.opacity
                                    class="fixed inset-0 z-50 flex items-center justify-center"
                                    aria-modal="true"
                                    role="dialog"
                                >
                                    <div class="absolute inset-0 bg-gray-900/50" x-on:click="openErrors = false"></div>

                                    <div class="relative z-10 w-full max-w-2xl rounded-3xl bg-white p-6 shadow-2xl dark:bg-gray-800">
                                        <div class="flex items-center justify-between">
                                            <h3 class="text-h5 font-semibold text-gray-900 dark:text-gray-100">
                                                <i class="fa-solid fa-exclamation-triangle text-red-600 mr-2"></i>
                                                {{ __('Errores de Validación') }}
                                            </h3>
                                            <button
                                                type="button"
                                                class="rounded-full p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-red-500 dark:hover:bg-gray-700"
                                                x-on:click="openErrors = false"
                                                aria-label="{{ __('Cerrar') }}"
                                            >
                                                <i class="fa-solid fa-xmark"></i>
                                            </button>
                                        </div>

                                        <div class="mt-4 max-h-96 space-y-3 overflow-y-auto pr-1">
                                            @php
                                                // Crear mapa de errores por file_id
                                                $errorsByFileId = [];
                                                if (isset($run->errors['files']) && is_array($run->errors['files'])) {
                                                    foreach ($run->errors['files'] as $fileError) {
                                                        if (isset($fileError['file_id'])) {
                                                            $errorsByFileId[$fileError['file_id']] = $fileError;
                                                        }
                                                    }
                                                }
                                            @endphp

                                            {{-- Display general message if exists --}}
                                            @if(isset($run->errors['message']))
                                                <div class="rounded-2xl bg-orange-50 border border-orange-200 p-4 dark:bg-orange-900/20 dark:border-orange-800">
                                                    <div class="flex items-start gap-2">
                                                        <i class="fa-solid fa-info-circle text-orange-600 mt-0.5"></i>
                                                        <p class="text-sm text-orange-800 dark:text-orange-300">{{ $run->errors['message'] }}</p>
                                                    </div>
                                                </div>
                                            @endif

                                            {{-- Display ALL files with their validation status --}}
                                            <div class="space-y-2">
                                                <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400 mb-2">
                                                    {{ __('Estado de validación por archivo') }}
                                                </h4>

                                                @forelse($run->files as $file)
                                                    @php
                                                        $hasError = isset($errorsByFileId[$file->id]);
                                                        $fileError = $errorsByFileId[$file->id] ?? null;
                                                    @endphp

                                                    <div class="rounded-2xl border p-4 {{ $hasError ? 'border-red-200 bg-red-50/50 dark:border-red-800 dark:bg-red-900/10' : 'border-green-200 bg-green-50/50 dark:border-green-800 dark:bg-green-900/10' }}">
                                                        {{-- Header: insumo esperado y status --}}
                                                        <div class="flex items-start justify-between gap-2">
                                                            <div class="flex-1 min-w-0">
                                                                <div class="flex items-center gap-2">
                                                                    @if($hasError)
                                                                        <i class="fa-solid fa-circle-xmark text-red-600 flex-shrink-0"></i>
                                                                    @else
                                                                        <i class="fa-solid fa-circle-check text-green-600 flex-shrink-0"></i>
                                                                    @endif
                                                                    <div class="flex-1 min-w-0">
                                                                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                                                            <i class="fa-solid fa-database text-xs mr-1"></i>
                                                                            {{ $file->dataSource->name ?? __('Sin insumo') }}
                                                                            @if($file->dataSource->code)
                                                                                <span class="text-xs font-normal text-gray-500">({{ $file->dataSource->code }})</span>
                                                                            @endif
                                                                        </p>
                                                                        <p class="text-xs text-gray-600 dark:text-gray-400 mt-0.5">
                                                                            <i class="fa-solid fa-file text-[10px] mr-1"></i>
                                                                            Archivo: <span class="font-medium">{{ $file->original_name }}</span>
                                                                        </p>
                                                                    </div>
                                                                </div>
                                                            </div>

                                                            {{-- Status badge --}}
                                                            <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold flex-shrink-0 {{ $hasError ? 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300' : 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300' }}">
                                                                {{ $hasError ? __('Fallida') : __('Exitosa') }}
                                                            </span>
                                                        </div>

                                                        {{-- Error details if exists --}}
                                                        @if($hasError && $fileError)
                                                            <div class="mt-3 ml-6 pl-3 border-l-2 border-red-300 dark:border-red-700">
                                                                <p class="text-xs font-semibold text-red-900 dark:text-red-200 mb-1">
                                                                    {{ __('Detalle del error:') }}
                                                                </p>
                                                                <p class="text-xs text-red-700 dark:text-red-400 whitespace-pre-line">
                                                                    {{ $fileError['error'] ?? __('Error desconocido') }}
                                                                </p>

                                                                {{-- Componente para reemplazar archivo --}}
                                                                <div class="mt-3 space-y-2" x-data="fileReplacer({{ $file->id }}, '{{ $file->dataSource->extension ?? 'csv' }}')">
                                                                    <div class="flex items-center gap-2">
                                                                        <label
                                                                            :for="'replace-file-' + fileId"
                                                                            class="inline-flex items-center gap-1 rounded-full bg-orange-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-orange-500 cursor-pointer"
                                                                            :class="{ 'opacity-50 cursor-not-allowed': uploading || success }"
                                                                        >
                                                                            <i class="fa-solid fa-upload"></i>
                                                                            <span x-text="success ? 'Reemplazado ✓' : (uploading ? 'Subiendo...' : 'Reemplazar archivo')"></span>
                                                                        </label>
                                                                        <input
                                                                            type="file"
                                                                            :id="'replace-file-' + fileId"
                                                                            @change="handleFileSelect($event)"
                                                                            :accept="acceptedExtensions"
                                                                            class="hidden"
                                                                            :disabled="uploading || success"
                                                                        />
                                                                        <span x-show="uploadProgress > 0 && uploadProgress < 100" class="text-xs text-blue-600 dark:text-blue-400" x-text="uploadProgress + '%'"></span>
                                                                    </div>
                                                                    <p class="text-xs text-gray-600 dark:text-gray-400" x-show="!uploading && !success">
                                                                        💡 Selecciona el archivo correcto para este insumo.
                                                                    </p>
                                                                    <p class="text-xs text-red-600 dark:text-red-400" x-show="error" x-text="error"></p>
                                                                    <p class="text-xs text-green-600 dark:text-green-400 font-semibold" x-show="success">
                                                                        <i class="fa-solid fa-check-circle"></i> Archivo reemplazado. Presiona "Re-validar archivos" cuando termines.
                                                                    </p>
                                                                </div>
                                                            </div>
                                                        @endif
                                                    </div>
                                                @empty
                                                    <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-4">
                                                        {{ __('No hay archivos asociados a este comunicado.') }}
                                                    </p>
                                                @endforelse
                                            </div>

                                            {{-- Unexpected error details if present --}}
                                            @if(isset($run->errors['details']) && !isset($run->errors['files']))
                                                <div class="rounded-2xl border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20">
                                                    <p class="text-xs font-semibold text-red-900 dark:text-red-200 mb-1">
                                                        {{ __('Error inesperado:') }}
                                                    </p>
                                                    <p class="text-xs text-red-700 dark:text-red-400">{{ $run->errors['details'] }}</p>
                                                </div>
                                            @endif
                                        </div>

                                        <div class="mt-6 flex items-center justify-between gap-3">
                                            {{-- Botón para re-lanzar validación --}}
                                            @php
                                                $hasFailedFiles = isset($run->errors['files']) && count($run->errors['files']) > 0;
                                                $totalFiles = $run->files->count();
                                                $expectedFiles = $run->type->dataSources->count();
                                                $canRevalidate = $totalFiles === $expectedFiles;
                                            @endphp

                                            @if($canRevalidate)
                                                <form method="POST" action="{{ route('recaudo.comunicados.revalidate', $run) }}">
                                                    @csrf
                                                    <button
                                                        type="submit"
                                                        class="inline-flex items-center gap-2 rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-800"
                                                    >
                                                        <i class="fa-solid fa-rotate"></i>
                                                        {{ __('Re-validar archivos') }}
                                                    </button>
                                                </form>
                                            @elseif($totalFiles < $expectedFiles)
                                                <p class="text-xs text-orange-600 dark:text-orange-400">
                                                    <i class="fa-solid fa-info-circle"></i>
                                                    Faltan {{ $expectedFiles - $totalFiles }} archivo(s) por cargar
                                                </p>
                                            @endif

                                            <button
                                                type="button"
                                                class="inline-flex items-center rounded-full bg-gray-200 dark:bg-gray-700 px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-300 transition hover:bg-gray-300 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-800"
                                                x-on:click="openErrors = false"
                                            >
                                                {{ __('Cerrar') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                        </div>
                        {{-- Cierre del x-data wrapper --}}
                    @empty
                        <div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-gray-300 px-6 py-12 text-center text-body text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            <i class="fa-regular fa-envelope-open text-2xl text-gray-400"></i>
                            <p>{{ __('Aún no hay comunicados programados.') }}</p>
                        </div>
                    @endforelse

                    @if (($runs ?? null) instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
                        <div>
                            {{ $runs->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @livewire('recaudo.comunicados.create-run-modal')

    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.on('collectionNoticeRunCreated', () => {
                window.location.reload();
            });
        });
    </script>
    <script>
        function deleteAndReloadFile(fileId, dataSourceName) {
            if (!confirm(`¿Estás seguro de eliminar el archivo del insumo "${dataSourceName}"?\n\nEsto eliminará el archivo del servidor y podrás cargar uno nuevo.`)) {
                return;
            }

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

            fetch(`/recaudo/comunicados/files/${fileId}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message || 'Archivo eliminado correctamente.');
                    window.location.reload();
                } else {
                    alert(data.message || 'Error al eliminar el archivo.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Ocurrió un error al eliminar el archivo. Por favor intenta de nuevo.');
            });
        }

        function fileReplacer(fileId, expectedExtension) {
            const getAcceptedExtensions = (extension) => {
                const extensionMap = {
                    'csv': '.csv',
                    'xlsx': '.xlsx,.xls',
                    'xls': '.xlsx,.xls',
                    'txt': '.txt'
                };
                return extensionMap[extension?.toLowerCase()] || '.csv,.xlsx,.xls';
            };

            return {
                fileId: fileId,
                uploading: false,
                uploadProgress: 0,
                error: '',
                success: false,
                acceptedExtensions: getAcceptedExtensions(expectedExtension),

                async handleFileSelect(event) {
                    const file = event.target.files[0];
                    if (!file) {
                        return;
                    }

                    // Validar tamaño (máximo {{ config('chunked-uploads.collection_notices.max_file_size', 512 * 1024 * 1024) }} bytes = 512MB)
                    const maxSize = {{ config('chunked-uploads.collection_notices.max_file_size', 512 * 1024 * 1024) }};
                    if (file.size > maxSize) {
                        this.error = 'El archivo es demasiado grande. Máximo ' + this.formatBytes(maxSize) + '.';
                        event.target.value = '';
                        return;
                    }

                    this.error = '';
                    this.uploading = true;
                    this.uploadProgress = 0;

                    try {
                        // Subir archivo usando sistema de chunks
                        const uploadedPath = await this.uploadFileInChunks(file);

                        // Llamar endpoint de reemplazo
                        const result = await this.replaceFile(uploadedPath);

                        // Mostrar mensaje de éxito sin recargar
                        this.uploading = false;
                        this.uploadProgress = 0;
                        this.error = '';
                        this.success = true;
                    } catch (error) {
                        console.error('Error al reemplazar archivo:', error);
                        this.error = error.message || 'Error al reemplazar el archivo. Intenta de nuevo.';
                        this.uploading = false;
                        this.uploadProgress = 0;
                        this.success = false;
                        event.target.value = '';
                    }
                },

                async uploadFileInChunks(file) {
                    const chunkSize = 1024 * 1024; // 1MB chunks
                    const totalChunks = Math.ceil(file.size / chunkSize);
                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                    const uploadId = this.generateUploadId();
                    const extension = file.name.split('.').pop();

                    for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
                        const start = chunkIndex * chunkSize;
                        const end = Math.min(start + chunkSize, file.size);
                        const chunk = file.slice(start, end);

                        const formData = new FormData();
                        formData.append('chunk', chunk);
                        formData.append('upload_id', uploadId);
                        formData.append('chunk_index', chunkIndex);
                        formData.append('total_chunks', totalChunks);
                        formData.append('original_name', file.name);
                        formData.append('size', file.size);
                        formData.append('mime', file.type);
                        formData.append('extension', extension);

                        const response = await fetch('/recaudo/comunicados/uploads/chunk', {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': csrfToken,
                                'Accept': 'application/json'
                            },
                            body: formData
                        });

                        if (!response.ok) {
                            throw new Error('Error al subir el archivo');
                        }

                        const data = await response.json();

                        // Actualizar progreso
                        this.uploadProgress = Math.round(((chunkIndex + 1) / totalChunks) * 100);

                        // Si es el último chunk, retornar la ruta del archivo
                        if (data.completed) {
                            return data.file?.path || null;
                        }
                    }
                },

                generateUploadId() {
                    const timestamp = Date.now().toString(36);
                    const randomStr = Math.random().toString(36).substring(2, 15);
                    return `replace_${timestamp}_${randomStr}`;
                },

                async replaceFile(uploadedPath) {
                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

                    const response = await fetch(`/recaudo/comunicados/files/${this.fileId}/replace`, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'Accept': 'application/json',
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            temp_path: uploadedPath
                        })
                    });

                    const data = await response.json();

                    if (!response.ok || !data.success) {
                        throw new Error(data.message || 'Error al reemplazar el archivo');
                    }

                    return data;
                },

                formatBytes(bytes) {
                    if (bytes <= 0) {
                        return '0 B';
                    }

                    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
                    let value = bytes;
                    let index = 0;

                    while (value >= 1024 && index < units.length - 1) {
                        value /= 1024;
                        index++;
                    }

                    const decimals = value >= 10 || index === 0 ? 0 : 1;
                    return value.toFixed(decimals) + ' ' + units[index];
                }
            };
        }
    </script>
</x-app-layout>
