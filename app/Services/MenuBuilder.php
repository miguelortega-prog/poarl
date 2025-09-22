<?php

namespace App\Services;

use App\DTOs\Menu\MenuItemDto;
use App\Repositories\Interfaces\MenuRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class MenuBuilder
{
    public function __construct(
        private readonly MenuRepositoryInterface $menuRepository
    ) {}

    /**
     * Construye la jerarquía de menús visibles para el usuario autenticado.
     *
     * @return array<int, MenuItemDto>
     */
    public function buildForCurrentUser(): array
    {
        $user = Auth::user();

        // Traemos todos los menús
        $menus = $this->menuRepository->getAllMenus();

        // Filtramos por permisos
        $allowed = $menus->filter(function (MenuItemDto $menu) use ($user) {
            return $menu->permission === null || $user->can($menu->permission);
        });

        // Armamos jerarquía
        return $this->buildHierarchy($allowed);
    }

    /**
     * Convierte lista plana a jerarquía padre/hijos
     *
     * @param Collection<int, MenuItemDto> $menus
     * @return array<int, MenuItemDto>
     */
    private function buildHierarchy(Collection $menus): array
    {
        $byId = $menus->keyBy(fn (MenuItemDto $m) => $m->id);

        foreach ($byId as $menu) {
            if ($menu->parentId && $byId->has($menu->parentId)) {
                $byId[$menu->parentId]->children[] = $menu;
            }
        }

        // Retornar solo los root
        return $byId->filter(fn ($m) => $m->parentId === null)->values()->all();
    }
}
