<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = collect(config('roles.registerable', []));

        if ($roles->isEmpty()) {
            return;
        }

        Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $roles)
            ->get()
            ->groupBy('name')
            ->each(static function ($group): void {
                $group->skip(1)->each->delete();
            });

        $roles->each(static function (string $role): void {
            Role::query()->updateOrCreate([
                'name' => $role,
                'guard_name' => 'web',
            ]);
        });
    }
}
