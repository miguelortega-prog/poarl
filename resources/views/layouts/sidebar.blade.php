<div class="min-h-screen flex bg-gray-100 dark:bg-gray-900">

    {{-- Sidebar --}}
    <aside class="w-64 bg-white dark:bg-gray-800 shadow-md">
        <div class="p-6">
            <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200">Menú</h2>
        </div>
        <nav class="mt-4 space-y-2">
            <x-nav-link href="{{ route('dashboard') }}" :active="request()->routeIs('dashboard')">
                {{ __('Dashboard') }}
            </x-nav-link>
            <x-nav-link href="#" :active="false">
                {{ __('Opción 1') }}
            </x-nav-link>
            <x-nav-link href="#" :active="false">
                {{ __('Opción 2') }}
            </x-nav-link>
        </nav>
    </aside>

    {{-- Contenido principal --}}
    <div class="flex-1 flex flex-col">
        {{-- Header original de Jetstream --}}
        @include('layouts.navigation')

        {{-- Contenido dinámico --}}
        <main class="flex-1 p-6">
            {{ $slot }}
        </main>
    </div>
</div>
