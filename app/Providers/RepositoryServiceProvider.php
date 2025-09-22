<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Repositories\EloquentMenuRepository;
use App\Repositories\Interfaces\MenuRepositoryInterface;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MenuRepositoryInterface::class, EloquentMenuRepository::class);
    }
}
