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
                    <div class="grid grid-cols-7 gap-4 rounded-2xl bg-gray-100 px-6 py-3 text-label font-semibold uppercase tracking-wide text-gray-700 dark:bg-gray-900/60 dark:text-gray-200">
                        <span>{{ __('Id Comunicado') }}</span>
                        <span>{{ __('Fecha Programación') }}</span>
                        <span>{{ __('Usuario Programación') }}</span>
                        <span>{{ __('Fecha Ejecución') }}</span>
                        <span>{{ __('Tiempo en Minutos') }}</span>
                        <span>{{ __('Estado') }}</span>
                        <span>{{ __('Resultados') }}</span>
                    </div>

                    <div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-gray-300 px-6 py-12 text-center text-body text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <i class="fa-regular fa-envelope-open text-2xl text-gray-400"></i>
                        <p>{{ __('Aún no hay comunicados programados.') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @livewire('recaudo.comunicados.create-run-modal')
</x-app-layout>
