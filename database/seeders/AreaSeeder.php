<?php

namespace Database\Seeders;

use App\Models\Area;
use Illuminate\Database\Seeder;

class AreaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $areas = [
            'ARL'
        ];

        foreach ($areas as $area) {
            Area::firstOrCreate(['name' => $area]);
        }
    }
}
