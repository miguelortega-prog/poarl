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
        Schema::create('collection_notice_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_notice_type_id')
                ->nullable()
                ->constrained('collection_notice_types')
                ->nullOnDelete();
            $table->foreignId('requested_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->enum('status', [
                'ready',
                'in_process',
                'finished',
                'closed',
                'cancelled'
                ])->default('ready');
            $table->timestamps();
            $table->index(['collection_notice_type_id', 'status']);
            $table->index('started_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collection_notice_runs');
    }
};
