<?php

declare(strict_types=1);

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
        Schema::table('collection_notice_runs', function (Blueprint $table): void {
            $table->string('period', 20)
                ->nullable()
                ->after('collection_notice_type_id')
                ->comment('Periodo para el procesamiento del comunicado (YYYYMM, *, o fecha calculada)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collection_notice_runs', function (Blueprint $table): void {
            $table->dropColumn('period');
        });
    }
};
