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
<body
    x-data="{
        sidebarOpen: false,
    }"
    x-on:toggle-sidebar.window="sidebarOpen = !sidebarOpen"
    x-on:open-sidebar.window="sidebarOpen = true"
    x-on:close-sidebar.window="sidebarOpen = false"
    x-on:keydown.escape.window="sidebarOpen = false"
    class="font-sans antialiased bg-gray-100 dark:bg-gray-900"
>

    <x-banner />

    {{-- Header Jetstream fijo arriba (≈ h-16) --}}
    @livewire('navigation-menu')

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

    {{-- Backdrop para el drawer móvil --}}
    @if ($shouldRenderSidebar)
        <div
            x-cloak
            x-show="sidebarOpen"
            x-transition.opacity
            @click="sidebarOpen = false"
            class="fixed inset-0 z-30 bg-black/40 md:hidden"></div>
    @endif

    {{-- Contenedor principal empujado debajo del header --}}
    <div class="min-h-screen pt-16">
        <div class="flex">

            {{-- Sidebar fijo: debajo del header, con drawer móvil --}}
            @if ($shouldRenderSidebar)
                <aside
                    class="fixed top-16 left-0 z-40 w-64 h-[calc(100vh-4rem)] overflow-y-auto
                           bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700
                           transform transition-transform duration-200 ease-in-out
                           -translate-x-full md:translate-x-0"
                    :class="sidebarOpen ? 'translate-x-0' : ''">
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

            {{-- Contenido principal (margen para el aside en md+) --}}
            <div @class(['w-full', 'flex-1 md:ml-64' => $shouldRenderSidebar])>
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
    @stack('modals')
    @livewireScripts
</body>
</html>
