<?php

/**
 * Script de validación: Compara cálculo de DV entre PHP y función PostgreSQL
 *
 * Uso:
 * docker-compose exec poarl-php php tests/validate_dv_function.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Helpers\NitHelper;
use Illuminate\Support\Facades\DB;

// Bootstrap Laravel
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "========================================\n";
echo "Validación de función calcular_dv_nit()\n";
echo "========================================\n\n";

// NITs de prueba con sus DVs calculados correctamente
$testCases = [
    ['nit' => '900373123', 'dv_esperado' => 2, 'descripcion' => 'NIT ejemplo 1'],
    ['nit' => '890903407', 'dv_esperado' => 9, 'descripcion' => 'NIT ejemplo 2'],
    ['nit' => '800197268', 'dv_esperado' => 4, 'descripcion' => 'NIT ejemplo 3'],
    ['nit' => '860007738', 'dv_esperado' => 9, 'descripcion' => 'NIT ejemplo 4'],
    ['nit' => '900123456', 'dv_esperado' => 8, 'descripcion' => 'NIT ejemplo 5'],
    ['nit' => '123456789', 'dv_esperado' => 6, 'descripcion' => 'NIT ejemplo 6'],
    ['nit' => '1234567890123', 'dv_esperado' => 8, 'descripcion' => 'NIT largo (13 dígitos)'],
    ['nit' => '999999999', 'dv_esperado' => 4, 'descripcion' => 'NIT con 9s'],
    ['nit' => '0', 'dv_esperado' => 0, 'descripcion' => 'NIT cero'],
    ['nit' => '', 'dv_esperado' => 0, 'descripcion' => 'NIT vacío'],
];

echo "Creando/actualizando función calcular_dv_nit() en PostgreSQL...\n";

// Crear función PL/pgSQL
DB::statement("
    CREATE OR REPLACE FUNCTION calcular_dv_nit(nit TEXT)
    RETURNS INTEGER AS \$function\$
    DECLARE
        pesos INTEGER[] := ARRAY[3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];
        nit_limpio TEXT;
        nit_invertido TEXT;
        suma INTEGER := 0;
        residuo INTEGER;
        longitud INTEGER;
        digito INTEGER;
        peso INTEGER;
    BEGIN
        nit_limpio := REGEXP_REPLACE(nit, '[^0-9]', '', 'g');

        IF nit_limpio = '' OR nit_limpio = '0' THEN
            RETURN 0;
        END IF;

        nit_invertido := REVERSE(nit_limpio);
        longitud := LENGTH(nit_invertido);

        FOR i IN 1..longitud LOOP
            digito := CAST(SUBSTRING(nit_invertido FROM i FOR 1) AS INTEGER);

            IF i <= array_length(pesos, 1) THEN
                peso := pesos[i];
            ELSE
                peso := 0;
            END IF;

            suma := suma + (digito * peso);
        END LOOP;

        residuo := suma % 11;

        IF residuo > 1 THEN
            RETURN 11 - residuo;
        ELSE
            RETURN residuo;
        END IF;
    END;
    \$function\$ LANGUAGE plpgsql IMMUTABLE;
");

echo "✓ Función creada\n\n";

// Tabla de resultados
echo "╔════════════════╦═══════════╦═════════════════╦════════════════╦══════════╗\n";
echo "║ NIT            ║ Esperado  ║ PHP Helper      ║ PostgreSQL     ║ Status   ║\n";
echo "╠════════════════╬═══════════╬═════════════════╬════════════════╬══════════╣\n";

$totalTests = count($testCases);
$passedTests = 0;
$failedTests = 0;

foreach ($testCases as $test) {
    $nit = $test['nit'];
    $dvEsperado = $test['dv_esperado'];

    // Calcular con PHP
    try {
        $dvPhp = NitHelper::calcularDigitoVerificacion($nit);
    } catch (\Exception $e) {
        $dvPhp = "ERROR: " . $e->getMessage();
    }

    // Calcular con PostgreSQL
    try {
        $result = DB::selectOne("SELECT calcular_dv_nit(?) as dv", [$nit]);
        $dvSql = $result->dv;
    } catch (\Exception $e) {
        $dvSql = "ERROR: " . $e->getMessage();
    }

    // Comparar resultados
    $phpMatch = ($dvPhp === $dvEsperado);
    $sqlMatch = ($dvSql === $dvEsperado);
    $bothMatch = ($dvPhp === $dvSql);

    if ($phpMatch && $sqlMatch && $bothMatch) {
        $status = "✓ PASS";
        $passedTests++;
    } else {
        $status = "✗ FAIL";
        $failedTests++;
    }

    $nitDisplay = str_pad($nit === '' ? '(vacío)' : $nit, 14);
    $expectedDisplay = str_pad((string)$dvEsperado, 9);
    $phpDisplay = str_pad((string)$dvPhp, 15);
    $sqlDisplay = str_pad((string)$dvSql, 14);

    echo "║ {$nitDisplay} ║ {$expectedDisplay} ║ {$phpDisplay} ║ {$sqlDisplay} ║ {$status}    ║\n";
}

echo "╚════════════════╩═══════════╩═════════════════╩════════════════╩══════════╝\n\n";

// Resumen
echo "========================================\n";
echo "RESUMEN\n";
echo "========================================\n";
echo "Total de tests:   {$totalTests}\n";
echo "Tests exitosos:   {$passedTests}\n";
echo "Tests fallidos:   {$failedTests}\n";
echo "\n";

if ($failedTests === 0) {
    echo "✓ TODOS LOS TESTS PASARON\n";
    echo "La función PL/pgSQL produce resultados idénticos al helper PHP.\n";
    echo "Puedes usar con confianza la optimización implementada.\n";
    exit(0);
} else {
    echo "✗ ALGUNOS TESTS FALLARON\n";
    echo "Revisa las diferencias entre PHP y PostgreSQL.\n";
    exit(1);
}
