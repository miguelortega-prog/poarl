<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('collection_notice_run_files', 'sha256')) {
            return;
        }

        Schema::table('collection_notice_run_files', function (Blueprint $table) {
            $table->dropColumn('sha256');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('collection_notice_run_files', 'sha256')) {
            return;
        }

        Schema::table('collection_notice_run_files', function (Blueprint $table) {
            $table->string('sha256', 64)->nullable();
        });
    }
};
