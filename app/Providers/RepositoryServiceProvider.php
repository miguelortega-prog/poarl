<?php

namespace App\Providers;


use Illuminate\Support\ServiceProvider;

use App\Repositories\{
    EloquentMenuRepository,
    CollectionNoticeRunEloquentRepository,
    CollectionNoticeRunFileEloquentRepository
};

use App\Repositories\Interfaces\{
    MenuRepositoryInterface,
    CollectionNoticeRunRepositoryInterface,
    CollectionNoticeRunFileRepositoryInterface
};

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $implementations = [
            MenuRepositoryInterface::class                    => EloquentMenuRepository::class,
            CollectionNoticeRunRepositoryInterface::class     => CollectionNoticeRunEloquentRepository::class,
            CollectionNoticeRunFileRepositoryInterface::class => CollectionNoticeRunFileEloquentRepository::class,
        ];

        foreach ($implementations as $interface => $implementation) {
            $this->app->bind($interface, $implementation);
        }
    }
}
