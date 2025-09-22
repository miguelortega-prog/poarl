<?php

namespace Database\Seeders;

use App\Models\Subdepartment;
use App\Models\Team;
use Illuminate\Database\Seeder;

class TeamSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $subdepartment = Subdepartment::where('name', 'Dirección Nacional Administrativa')->first();

        $teams = [
            'Afiliaciones y Novedades',
            'Recaudo',
            'Gestion Empresarial',
            'Optimización y Automatización',
        ];

        foreach ($teams as $team) {
            Team::firstOrCreate([
                'subdepartment_id' => $subdepartment->id,
                'name'             => $team
            ]);
        }
    }
}
