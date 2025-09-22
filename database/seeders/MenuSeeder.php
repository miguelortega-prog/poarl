<?php

namespace Database\Seeders;

use App\Models\Menu;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
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
        $roles = ['manager', 'director', 'teamLead', 'teamCoordinator', 'teamMember'];
        foreach ($roles as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if ($role) {
                $comunicados->roles()->syncWithoutDetaching([$role->id]);
            }
        }
    }
}
