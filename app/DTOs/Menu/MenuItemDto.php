<?php

namespace App\DTOs\Menu;

final class MenuItemDto
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $parentId,
        public readonly string $name,
        public readonly ?string $route,
        public readonly ?string $icon,
        public readonly int $order,
        public readonly ?string $permission,
        /** @var MenuItemDto[] */
        public array $children = [],
    ) {}
}
