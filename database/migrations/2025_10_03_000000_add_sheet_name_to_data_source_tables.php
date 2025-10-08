<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tables = [
            'data_source_bascar',
            'data_source_pagapl',
            'data_source_baprpo',
            'data_source_pagpla',
            'data_source_datpol',
            'data_source_dettra',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('sheet_name', 100)->nullable()->after('run_id');
                $table->index(['run_id', 'sheet_name']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'data_source_bascar',
            'data_source_pagapl',
            'data_source_baprpo',
            'data_source_pagpla',
            'data_source_datpol',
            'data_source_dettra',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropIndex(['run_id', 'sheet_name']);
                $table->dropColumn('sheet_name');
            });
        }
    }
};
