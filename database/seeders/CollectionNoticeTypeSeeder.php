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
            ['name' => 'CONSTITUCIÓN EN MORA - APORTANTES', 'code' => 'CMA'],
            ['name' => 'CONSTITUCIÓN EN MORA - INDEPENDIENTES', 'code' => 'CMI'],
            ['name' => 'AVISO DE INCUMPLIMIENTO - APORTANTES', 'code' => 'AIA'],
            ['name' => 'AVISO DE INCUMPLIMIENTO POR INCONSISTENCIAS', 'code' => 'AIINCO'],
            ['name' => 'AVISO DE INCUMPLIMIENTO POR ESTADOS DE CUENTA', 'code' => 'AIESTA'],
            ['name' => 'AVISO DE INCUMPLIMIENTO - INDEPENDIENTES', 'code' => 'AIINDE'],
            ['name' => 'AVISO AL MINISTERIO - APORTANTES', 'code' => 'AIMIN'],
            ['name' => 'TÍTULO EJECUTIVO - APORTANTES', 'code' => 'TEA'],
            ['name' => 'PRIMERA ACCIÓN PERSUASIVA - APORTANTES', 'code' => 'PAP'],
            ['name' => 'SEGUNDA ACCIÓN PERSUASIVA -  APORTANTES', 'code' => 'SAP'],
        ];

        foreach ($types as $t) {
            CollectionNoticeType::updateOrCreate(
                ['code' => $t['code']],
                ['name' => $t['name']] 
            );
        }
        
    }
}
