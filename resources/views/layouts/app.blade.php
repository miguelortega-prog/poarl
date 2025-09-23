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
        desktopBreakpoint: 1200,
        sidebarOpen: window.innerWidth >= 1200,          // abierto en ≥ desktop
    }"
    x-on:resize.window="sidebarOpen = window.innerWidth >= desktopBreakpoint"
    x-on:toggle-sidebar.window="sidebarOpen = ! sidebarOpen"
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

    {{-- Contenedor principal: grid en desktop con 16rem de sidebar --}}
    <div class="min-h-[calc(100vh-4rem)] desktop:grid desktop:grid-cols-[16rem_1fr]">
        <x-layout.sidebar
            :should-render="$shouldRenderSidebar"
            :has-custom-sidebar="$hasCustomSidebar"
            :menu-tree="$defaultMenuTree"
        >
            @isset($sidebar)
                {{ $sidebar }}
            @endisset
        </x-layout.sidebar>

        {{-- Contenido principal --}}
        <main class="w-full desktop:col-start-2 p-4">
            {{ $slot }}
        </main>
    </div>

    <script src="https://kit.fontawesome.com/8ce750f5df.js" crossorigin="anonymous"></script>
    @stack('modals')
    @livewireScripts
</body>

</html>