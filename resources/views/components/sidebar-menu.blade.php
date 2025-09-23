@props(['menus'])

@php
    $resolveMenuUrl = static function (?string $route): string {
        if (blank($route)) {
            return '#';
        }

        if (filter_var($route, FILTER_VALIDATE_URL)) {
            return $route;
        }

        if (str_starts_with($route, '/')) {
            return $route;
        }

        if (\Illuminate\Support\Facades\Route::has($route)) {
            return route($route);
        }

        return '#';
    };
@endphp

<nav aria-label="{{ __('Menú principal') }}">
    <ul class="space-y-1">
        @foreach ($menus as $menu)
            <li x-data="{ open: false }">
                @php($menuUrl = $resolveMenuUrl($menu->route))
                <a
                    href="{{ $menuUrl }}"
                    target="_self"
                    @if($menu->children)
                        @click.prevent="open = !open"
                        x-bind:aria-expanded="open"
                        aria-haspopup="true"
                    @endif
                    class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-gray-700 transition-colors duration-150 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:focus-visible:ring-offset-gray-800"
                >
                    <i class="fas fa-{{ $menu->icon ?? 'circle' }} w-5 shrink-0"></i>
                    <span class="flex-1 text-left">{{ $menu->name }}</span>
                    @if ($menu->children)
                        <i :class="open ? 'fas fa-chevron-down' : 'fas fa-chevron-right'" class="ml-auto"></i>
                    @endif
                </a>

                @if ($menu->children)
                    <ul x-show="open" x-collapse class="ml-8 mt-2 space-y-1">
                        @foreach ($menu->children as $child)
                            <li>
                                @php($childUrl = $resolveMenuUrl($child->route))
                                <a
                                    href="{{ $childUrl }}"
                                    target="_self"
                                    class="flex items-center gap-3 rounded-md px-3 py-2 text-sm text-gray-600 transition-colors duration-150 hover:bg-gray-200 dark:text-gray-300 dark:hover:bg-gray-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:focus-visible:ring-offset-gray-800"
                                >
                                    <i class="fas fa-{{ $child->icon ?? 'circle' }} w-4 shrink-0"></i>
                                    <span>{{ $child->name }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </li>
        @endforeach
    </ul>
</nav>
