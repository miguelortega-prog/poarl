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
        Schema::table('data_source_basact', function (Blueprint $table) {
            // Renombrar columnas que empiezan con números a nombres sin números
            // PostgreSQL tiene problemas con nombres de columnas que empiezan con dígitos
            $table->renameColumn('1_nombre_trabajador', 'primer_nombre_trabajador');
            $table->renameColumn('2_nombre_trabajador', 'segundo_nombre_trabajador');
            $table->renameColumn('1_apellido_trabajador', 'primer_apellido_trabajador');
            $table->renameColumn('2_apellido_trabajador', 'segundo_apellido_trabajador');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('data_source_basact', function (Blueprint $table) {
            // Revertir los cambios
            $table->renameColumn('primer_nombre_trabajador', '1_nombre_trabajador');
            $table->renameColumn('segundo_nombre_trabajador', '2_nombre_trabajador');
            $table->renameColumn('primer_apellido_trabajador', '1_apellido_trabajador');
            $table->renameColumn('segundo_apellido_trabajador', '2_apellido_trabajador');
        });
    }
};
