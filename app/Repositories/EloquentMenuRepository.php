<?php

namespace App\Repositories;

use App\DTOs\Menu\MenuItemDto;
use App\Models\Menu;
use App\Repositories\Interfaces\MenuRepositoryInterface;
use Illuminate\Support\Collection;

class EloquentMenuRepository implements MenuRepositoryInterface
{
    public function getAllMenus(): Collection
    {
        return Menu::orderBy('order')
            ->get()
            ->map(fn (Menu $menu) => new MenuItemDto(
                id: $menu->id,
                parentId: $menu->parent_id,
                name: $menu->name,
                route: $menu->route,
                icon: $menu->icon,
                order: $menu->order,
                permission: $menu->permission,
            ));
    }
}
