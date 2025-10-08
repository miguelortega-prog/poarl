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
        Schema::table('collection_notice_runs', function (Blueprint $table) {
            $table->string('official_id')->nullable()->after('requested_by_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collection_notice_runs', function (Blueprint $table) {
            $table->dropColumn('official_id');
        });
    }
};
