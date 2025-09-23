<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Laravel') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body
    x-data="{
        sidebarOpen: window.innerWidth >= 1024,          // abierto en ≥lg
    }"
    x-on:resize.window="sidebarOpen = window.innerWidth >= 1024"
    class="font-sans antialiased bg-gray-100 dark:bg-gray-900"
>
    <style>[x-cloak]{display:none!important}</style>

    <x-banner />

    {{-- Header Jetstream fijo arriba --}}
    <header class="sticky top-0 z-50 bg-white/80 backdrop-blur border-b border-gray-200 dark:bg-gray-900/80 dark:border-gray-700">
        @livewire('navigation-menu')
    </header>

    @php
        $hasCustomSidebar = isset($sidebar)
            && $sidebar instanceof \Illuminate\View\ComponentSlot
            && ! $sidebar->isEmpty();

        $defaultMenuTree = [];
        if (! $hasCustomSidebar && auth()->check()) {
            $defaultMenuTree = app(\App\Services\MenuBuilder::class)->buildForCurrentUser();
        }
        $shouldRenderSidebar = $hasCustomSidebar || auth()->check();
    @endphp

    {{-- Contenedor principal: grid en md+ con 16rem de sidebar --}}
    <div class="min-h-[calc(100vh-4rem)] lg:grid lg:grid-cols-[16rem_1fr]">
        @if ($shouldRenderSidebar)
            {{-- Overlay móvil: arranca debajo del header (top-16) para no apagarlo --}}
            <div
                x-cloak
                x-show="sidebarOpen && window.innerWidth < 1024"
                x-transition.opacity
                class="fixed inset-0 top-16 z-40 bg-black/40 lg:hidden"
                @click="sidebarOpen = false"
            ></div>

            {{-- Sidebar: drawer en móvil, sticky en desktop --}}
            <aside
                x-cloak
                x-show="sidebarOpen"
                x-transition:enter="transition-transform duration-200 ease-out"
                x-transition:enter-start="-translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transition-transform duration-150 ease-in"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="-translate-x-full"

                class="
                    fixed z-50 left-0 top-16 h-[calc(100vh-4rem)] w-64 overflow-y-auto
                    bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700
                    lg:static lg:z-auto lg:translate-x-0 lg:block
                    lg:h-[calc(100vh-4rem)]
                "
            >
                @if ($hasCustomSidebar)
                    {{ $sidebar }}
                @else
                    <div class="p-4 space-y-4">
                        <p class="text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">
                            {{ __('Menú principal') }}
                        </p>
                        @if (count($defaultMenuTree) > 0)
                            <x-sidebar-menu :menus="$defaultMenuTree" />
                        @else
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                {{ __('Todavía no tienes accesos asignados.') }}
                            </p>
                        @endif
                    </div>
                @endif
            </aside>
        @endif

        {{-- Contenido principal --}}
        <main class="w-full lg:col-start-2 p-4">
            {{ $slot }}
        </main>
    </div>

    <script src="https://kit.fontawesome.com/8ce750f5df.js" crossorigin="anonymous"></script>
    @stack('modals')
    @livewireScripts
</body>

</html>