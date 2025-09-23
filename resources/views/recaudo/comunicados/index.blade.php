<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Comunicados Cartera') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xl sm:rounded-lg p-6">
                <p class="text-gray-700 dark:text-gray-300">
                    <div class="w-full p-4 bg-primary-900">
                        Hola mundo <h1 class="text-h1 desktop:font-bold text-primary-100">Título</h1>
                    </div>
                </p>
            </div>
        </div>
    </div>
</x-app-layout>
