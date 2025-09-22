<?php

namespace Database\Seeders;

use App\Models\Menu;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class MenuSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Crear/Reusar menú raíz
        $recaudo = Menu::updateOrCreate(
            ['name' => 'Recaudo'],
            [
                'parent_id' => null,
                'route' => null,
                'icon' => 'cash-icon',
                'order' => 1
            ]
        );

        // Crear/Reusar submenú
        $comunicados = Menu::updateOrCreate(
            ['name' => 'Comunicados Cartera'],
            [
                'parent_id' => $recaudo->id,
                'route'     => 'recaudo.comunicados.index',
                'icon'      => 'document-icon',
                'order'     => 1,
                'permission'=> 'view_comunicados'
            ]
        );

        // Asociar roles
        $roleNames = ['administrator', 'manager', 'director', 'teamLead', 'teamCoordinator', 'teamMember'];
        $roles = Role::whereIn('name', $roleNames)->get()->keyBy('name');

        $permission = $comunicados->permission
            ? Permission::firstOrCreate([
                'name' => $comunicados->permission,
                'guard_name' => 'web',
            ])
            : null;

        foreach ($roleNames as $roleName) {
            $role = $roles->get($roleName);

            if (! $role) {
                continue;
            }

            if ($permission) {
                $role->givePermissionTo($permission);
            }

            $comunicados->roles()->syncWithoutDetaching([$role->id]);
        }

        if ($administrator = $roles->get('administrator')) {
            Menu::all()->each(static function (Menu $menu) use ($administrator): void {
                $menu->roles()->syncWithoutDetaching([$administrator->id]);
            });

            $administrator->syncPermissions(Permission::all());
        }
    }
}
