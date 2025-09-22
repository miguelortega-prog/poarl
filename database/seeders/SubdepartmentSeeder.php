<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Subdepartment;
use Illuminate\Database\Seeder;

class SubdepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $area = Area::where('name', 'ARL')->first();

        $subdepartments = [
            'Dirección Nacional Administrativa',
            'Subgerencia Nacional Prevención',
            'Dirección Nacional Cuidado Al Trabajador',
            'Dirección Aseguramiento Legal',
            'Dirección Analítica y Gestón Financiera',
            'Dirección de Innovación'
        ];

        foreach ($subdepartments as $subdepartment) {
            Subdepartment::firstOrCreate([
                'area_id' => $area->id,
                'name'    => $subdepartment
            ]);
        }
    }
}
