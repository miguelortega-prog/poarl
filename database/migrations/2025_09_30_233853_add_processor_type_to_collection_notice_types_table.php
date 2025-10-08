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
        Schema::table('collection_notice_types', function (Blueprint $table): void {
            $table->string('processor_type', 100)
                ->nullable()
                ->after('period')
                ->comment('Identificador del procesador para ejecutar la lógica del comunicado');

            $table->index('processor_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collection_notice_types', function (Blueprint $table): void {
            $table->dropIndex(['processor_type']);
            $table->dropColumn('processor_type');
        });
    }
};
