<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>

    <!-- Styles -->
    @livewireStyles
</head>
<body x-data="{ sidebarOpen: false }" class="font-sans antialiased bg-gray-100 dark:bg-gray-900">

    <x-banner />

    {{-- Header Jetstream fijo arriba (≈ h-16) --}}
    @livewire('navigation-menu')

    {{-- Backdrop para el drawer móvil --}}
    <div
        x-show="sidebarOpen"
        x-transition.opacity
        @click="sidebarOpen = false"
        class="fixed inset-0 z-30 bg-black/40 md:hidden"></div>

    {{-- Contenedor principal empujado debajo del header --}}
    <div class="pt-16">
        <div class="flex">

            {{-- Sidebar fijo: debajo del header, con drawer móvil --}}
            <aside
                class="fixed top-16 left-0 z-40 w-64 h-[calc(100vh-4rem)] overflow-y-auto
                       bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700
                       transform transition-transform duration-200 ease-in-out
                       md:translate-x-0"
                :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0'">

                <div class="p-4">
                    @php
                        $menus = app(\App\Services\MenuBuilder::class)->buildForCurrentUser();
                    @endphp
                    <x-sidebar-menu :menus="$menus"/>
                </div>
            </aside>

            {{-- Contenido principal (margen para el aside en md+) --}}
            <div class="flex-1 md:ml-64 w-full">
                {{-- Top bar opcional (solo si quieres un botón hamburguesa adicional) --}}
                <div class="md:hidden bg-white dark:bg-gray-800 shadow px-4 py-2">
                    <button @click="sidebarOpen = !sidebarOpen" class="text-gray-600 dark:text-gray-300">
                        <i class="fas fa-bars"></i>
                    </button>
                </div>

                {{-- Header opcional de página --}}
                @if (isset($header))
                    <header class="bg-white dark:bg-gray-800 shadow">
                        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </header>
                @endif

                {{-- Slot principal --}}
                <main class="p-6">
                    {{ $slot }}
                </main>
            </div>
        </div>
    </div>
    <script src="https://kit.fontawesome.com/8ce750f5df.js" crossorigin="anonymous"></script>
    @livewireScripts
</body>
</html>
