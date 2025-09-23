<?php

namespace Database\Seeders;

use App\Models\CollectionNoticeType;
use App\Models\NoticeDataSource;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CollectionNoticeTypeDataSourceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $map = [
            'CMA'    => ['BASCAR', 'PAGAPL', 'BAPRPO', 'PAGPLA', 'DATPOL', 'DETTRA'],
            'CMI'    => ['PAGLOG', 'PAGPLA', 'DETTRA', 'BASACT'],
            'AIA'    => ['BASCAR', 'PAGAPL', 'BAPRPO', 'PAGPLA', 'DATPOL', 'DETTRA'],
            'AIINCO' => ['BASCAR', 'DATPOL', 'ARCTOT', 'DEC3033'],
            'AIESTA' => ['BASCAR', 'DATPOL', 'ESCUIN'],
            'AIINDE' => ['PAGLOG', 'PAGPLA', 'DETTRA', ],
            'AIMIN'  => ['BASCAR', 'PAGAPL', 'BAPRPO', 'PAGPLA', 'DATPOL', 'DETTRA', 'DIRMIN'],
            'TEA'    => ['BASCAR', 'PAGAPL', 'BAPRPO', 'PAGPLA', 'DATPOL', 'DETTRA'],
            'PAP'    => ['BASCAR', 'PAGAPL', 'BAPRPO', 'PAGPLA', 'DATPOL', 'DETTRA'],
            'SAP'    => ['BASCAR', 'PAGAPL', 'BAPRPO', 'PAGPLA', 'DATPOL', 'DETTRA'],
        ];

        DB::transaction(function () use ($map) {
            $typeIds   = CollectionNoticeType::pluck('id', 'code')->all();
            $sourceIds = NoticeDataSource::pluck('id', 'code')->all();

            foreach ($map as $typeCode => $sourceCodes) {
                if (!isset($typeIds[$typeCode])) {
                    throw new RuntimeException("CollectionNoticeType code no encontrado: {$typeCode}");
                }

                $ids = [];
                foreach ((array) $sourceCodes as $code) {
                    if (!isset($sourceIds[$code])) {
                        throw new RuntimeException("NoticeDataSource code no encontrado para '{$typeCode}': {$code}");
                    }
                    $ids[] = $sourceIds[$code];
                }

                CollectionNoticeType::findOrFail($typeIds[$typeCode])
                    ->dataSources()
                    ->sync($ids);                

            }
        });
    }
}
