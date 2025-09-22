<?php

namespace App\Providers;

use App\Actions\Jetstream\DeleteUser;
use Illuminate\Support\ServiceProvider;
use Laravel\Jetstream\Jetstream;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Role;
use App\Models\Area;
use App\Models\Subdepartment;
use App\Models\Team;


class JetstreamServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePermissions();

        Jetstream::deleteUsersUsing(DeleteUser::class);

        View::composer('auth.register', function ($view) {
            $registerableRoles = config('roles.registerable', []);

            $roles = Role::query()
                ->where('guard_name', 'web')
                ->whereIn('name', $registerableRoles)
                ->get()
                ->unique('name')
                ->values();

            if (! empty($registerableRoles)) {
                $orderMap = array_flip($registerableRoles);
                $roles = $roles->sortBy(static fn (Role $role) => $orderMap[$role->name] ?? PHP_INT_MAX)->values();
            }

            $view->with('roles', $roles);
            $view->with('areas', Area::all());
            $view->with('subdepartments', Subdepartment::all());
            $view->with('teams', Team::all());
        });
    }

    /**
     * Configure the permissions that are available within the application.
     */
    protected function configurePermissions(): void
    {
        Jetstream::defaultApiTokenPermissions(['read']);

        Jetstream::permissions([
            'create',
            'read',
            'update',
            'delete',
        ]);
    }
}
