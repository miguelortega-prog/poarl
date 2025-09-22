<?php

namespace App\Repositories\Interfaces;

use App\DTOs\Menu\MenuItemDto;
use Illuminate\Support\Collection;

interface MenuRepositoryInterface
{
    /**
     * Devuelve todos los menús disponibles en forma de DTOs.
     *
     * @return Collection<int, MenuItemDto>
     */
    public function getAllMenus(): Collection;
}
