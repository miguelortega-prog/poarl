<?php

namespace App\Providers;

use App\Actions\Jetstream\DeleteUser;
use Illuminate\Support\ServiceProvider;
use Laravel\Jetstream\Jetstream;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use App\Models\Area;
use App\Models\Subdepartment;
use App\Models\Team;
use App\Livewire\Profile\UpdateProfileInformationForm as ProfileUpdateProfileInformationForm;


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

        Livewire::component('profile.update-profile-information-form', ProfileUpdateProfileInformationForm::class);

        View::composer('auth.register', function ($view) {
            $view->with('roles', Role::all());
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
