@props(['menus'])

<nav>
    <ul class="space-y-2">
        @foreach ($menus as $menu)
            <li x-data="{ open: false }">
                <a href="{{ $menu->route ?? '#' }}" 
                   @if($menu->children) @click.prevent="open = !open" @endif
                   class="flex items-center p-2 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded">
                    <i class="fas fa-{{ $menu->icon ?? 'circle' }} w-5"></i>
                    <span class="ml-2">{{ $menu->name }}</span>
                    @if($menu->children)
                        <i :class="open ? 'fas fa-chevron-down ml-auto' : 'fas fa-chevron-right ml-auto'"></i>
                    @endif
                </a>

                @if($menu->children)
                    <ul x-show="open" x-collapse class="ml-6 mt-2 space-y-1">
                        @foreach ($menu->children as $child)
                            <li>
                                <a href="{{ $child->route }}" 
                                   class="flex items-center p-2 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 rounded">
                                    <i class="fas fa-{{ $child->icon ?? 'circle' }} w-4"></i>
                                    <span class="ml-2">{{ $child->name }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </li>
        @endforeach
    </ul>
</nav>
