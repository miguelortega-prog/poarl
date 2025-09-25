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
                        class="col-span-12 desktop:col-span-3 inline-flex w-full items-center justify-center gap-2 rounded-3xl bg-secondary-900 px-5 py-3 text-button font-semibold text-primary transition hover:bg-secondary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-900 desktop:w-auto desktop:justify-self-end"
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
                        <span>{{ __('Fecha Programación') }}</span>
                        <span>{{ __('Usuario Programación') }}</span>
                        <span>{{ __('Fecha Ejecución') }}</span>
                        <span>{{ __('Tiempo en Minutos') }}</span>
                        <span>{{ __('Estado') }}</span>
                        <span>{{ __('Resultados') }}</span>
                        <span class="text-center">{{ __('Archivos') }}</span>
                    </div>

                    @forelse ($runs ?? [] as $run)
                        <div class="grid grid-cols-8 items-center gap-4 rounded-2xl border border-gray-200 px-6 py-4 text-body text-gray-700 transition dark:border-gray-700 dark:text-gray-200">
                            <span class="font-semibold text-gray-900 dark:text-gray-100">#{{ $run->id }}</span>
                            <span>{{ optional($run->created_at)->format('d/m/Y H:i') ?? __('Sin programación') }}</span>
                            <span>{{ $run->requestedBy?->name ?? __('No asignado') }}</span>
                            <span>
                                @if ($run->started_at)
                                    {{ $run->started_at->format('d/m/Y H:i') }}
                                @else
                                    {{ __('Pendiente') }}
                                @endif
                            </span>
                            <span>
                                @if ($run->duration_ms)
                                    {{ (int) ceil($run->duration_ms / 60000) }}
                                @else
                                    {{ __('N/D') }}
                                @endif
                            </span>
                            <span class="capitalize">
                                {{ __($run->status) }}
                            </span>
                            <span>
                                {{ $run->type?->name ?? __('Sin tipo') }}
                            </span>
                            <div x-data="{ open: false }" class="relative flex justify-center">
                                <button
                                    type="button"
                                    x-on:click="open = true"
                                    class="inline-flex items-center justify-center rounded-full bg-primary/10 p-2 text-primary transition hover:bg-primary/20 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-900"
                                    aria-label="{{ __('Ver archivos de insumo') }}"
                                >
                                    <i class="fa-solid fa-folder-open text-lg"></i>
                                    @if ($run->files->isNotEmpty())
                                        <span class="sr-only">{{ trans_choice(':count archivo|:count archivos', $run->files->count()) }}</span>
                                    @endif
                                </button>

                                <div
                                    x-cloak
                                    x-show="open"
                                    x-transition.opacity
                                    class="fixed inset-0 z-50 flex items-center justify-center"
                                    aria-modal="true"
                                    role="dialog"
                                >
                                    <div class="absolute inset-0 bg-gray-900/50" x-on:click="open = false"></div>

                                    <div class="relative z-10 w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl dark:bg-gray-800">
                                        <div class="flex items-center justify-between">
                                            <h3 class="text-h5 font-semibold text-gray-900 dark:text-gray-100">
                                                {{ __('Archivos de insumo') }}
                                            </h3>
                                            <button
                                                type="button"
                                                class="rounded-full p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-primary-500"
                                                x-on:click="open = false"
                                                aria-label="{{ __('Cerrar') }}"
                                            >
                                                <i class="fa-solid fa-xmark"></i>
                                            </button>
                                        </div>

                                        <div class="mt-4 max-h-64 space-y-3 overflow-y-auto pr-1">
                                            @forelse ($run->files as $file)
                                                <div class="rounded-2xl border border-gray-200 p-4 dark:border-gray-700">
                                                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $file->original_name }}</p>
                                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                                        {{ $file->dataSource?->name ?? __('Sin origen') }}
                                                        •
                                                        {{ $file->uploader?->name ?? __('Sin usuario') }}
                                                    </p>
                                                    <p class="mt-1 text-xs text-gray-400">
                                                        {{ optional($file->created_at)->format('d/m/Y H:i') ?? __('Sin fecha') }}
                                                    </p>
                                                </div>
                                            @empty
                                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No hay archivos asignados a este comunicado.') }}</p>
                                            @endforelse
                                        </div>

                                        <div class="mt-6 flex justify-end">
                                            <button
                                                type="button"
                                                class="inline-flex items-center rounded-full bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-800"
                                                x-on:click="open = false"
                                            >
                                                {{ __('Cerrar') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
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
</x-app-layout>
