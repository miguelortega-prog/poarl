<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->call(AreaSeeder::class);
        $this->call(SubdepartmentSeeder::class);
        $this->call(TeamSeeder::class);
        $this->call(AdminUserSeeder::class);
        $this->call(MenuSeeder::class);
        $this->call(CityDeptoSeeder::class);
        $this->call([
            CollectionNoticeTypeSeeder::class,
            NoticeDataSourceSeeder::class,
            CollectionNoticeTypeDataSourceSeeder::class,
	]);
	$this->call(CollectionNoticeTypeProcessorSeeder::class);
    }
}
