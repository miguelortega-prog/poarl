@props([
    'shouldRender' => false,
    'hasCustomSidebar' => false,
    'menuTree' => [],
])

@if ($shouldRender)
    {{-- Overlay móvil: arranca debajo del header (top-16) para no cubrirlo --}}
    <div
        x-cloak
        x-show="sidebarOpen && window.innerWidth < desktopBreakpoint"
        x-transition.opacity
        class="fixed inset-0 top-16 z-40 bg-black/40 desktop:hidden"
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
        class="fixed left-0 top-16 z-50 h-[calc(100vh-4rem)] w-64 overflow-y-auto border-r border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800 desktop:static desktop:z-auto desktop:block desktop:translate-x-0 desktop:h-[calc(100vh-4rem)]"
    >
        @if ($hasCustomSidebar)
            {{ $slot }}
        @else
            <div class="space-y-4 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
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
        @endif
    </aside>
@endif

