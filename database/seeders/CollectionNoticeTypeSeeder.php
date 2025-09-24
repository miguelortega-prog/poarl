<?php

namespace Database\Seeders;

use App\Models\CollectionNoticeType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CollectionNoticeTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            ['name' => 'CONSTITUCIÓN EN MORA - APORTANTES', 'code' => 'CMA', 'period' => 'today-2'],
            ['name' => 'CONSTITUCIÓN EN MORA - INDEPENDIENTES', 'code' => 'CMI', 'period' => 'today-2'],
            ['name' => 'AVISO DE INCUMPLIMIENTO - APORTANTES', 'code' => 'AIA', 'period' => 'today-2'],
            ['name' => 'AVISO DE INCUMPLIMIENTO POR INCONSISTENCIAS', 'code' => 'AIINCO', 'period' => 'today-2'],
            ['name' => 'AVISO DE INCUMPLIMIENTO POR ESTADOS DE CUENTA', 'code' => 'AIESTA', 'period' => 'today-2'],
            ['name' => 'AVISO DE INCUMPLIMIENTO - INDEPENDIENTES', 'code' => 'AIINDE', 'period' => 'today-2'],
            ['name' => 'AVISO AL MINISTERIO - APORTANTES', 'code' => 'AIMIN', 'period' => 'write'],
            ['name' => 'TÍTULO EJECUTIVO - APORTANTES', 'code' => 'TEA', 'period' => 'all'],
            ['name' => 'PRIMERA ACCIÓN PERSUASIVA - APORTANTES', 'code' => 'PAP', 'period' => 'all'],
            ['name' => 'SEGUNDA ACCIÓN PERSUASIVA -  APORTANTES', 'code' => 'SAP', 'period' => 'all'],
        ];

        foreach ($types as $t) {
            CollectionNoticeType::updateOrCreate(
                ['code' => $t['code']],
                ['name' => $t['name']] 
            );
        }
        
    }
}
