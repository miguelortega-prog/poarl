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
        Schema::table('users', function (Blueprint $table) {
            $table->string('position')->nullable();
            $table->foreignId('supervisor_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('area_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('subdepartment_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('team_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['supervisor_id', 'team_id']);
            $table->dropColumn(['supervisor_id','position','team_id']);
        });
    }
};
