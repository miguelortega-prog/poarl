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
            $table->json('results')
                ->nullable()
                ->after('errors')
                ->comment('Resultados del procesamiento del comunicado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collection_notice_runs', function (Blueprint $table): void {
            $table->dropColumn('results');
        });
    }
};
