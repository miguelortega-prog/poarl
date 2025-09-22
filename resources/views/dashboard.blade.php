<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    @php
        $menuTree = app(\App\Services\MenuBuilder::class)->buildForCurrentUser();
    @endphp

    <x-slot name="sidebar">
        <div class="p-4 space-y-4">
            <p class="text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">
                {{ __('Menú principal') }}
            </p>

            @if (count($menuTree) > 0)
                <x-sidebar-menu :menus="$menuTree" />
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Todavía no tienes accesos asignados.') }}
                </p>
            @endif
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xl sm:rounded-lg">
                <x-welcome />
            </div>
        </div>
    </div>
</x-app-layout>

