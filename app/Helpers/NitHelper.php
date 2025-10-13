<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Helper para operaciones con NIT (Número de Identificación Tributaria).
 *
 * Proporciona utilidades para calcular dígitos de verificación y formatear NITs.
 */
final class NitHelper
{
    /**
     * Pesos para el cálculo del dígito de verificación según normativa DIAN.
     */
    private const PESOS = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];

    /**
     * Calcula el dígito de verificación de un NIT.
     *
     * Algoritmo oficial de la DIAN (Dirección de Impuestos y Aduanas Nacionales de Colombia):
     * 1. Invertir el NIT
     * 2. Multiplicar cada dígito por su peso correspondiente
     * 3. Sumar todos los productos
     * 4. Calcular el residuo de la división por 11
     * 5. Si residuo > 1, DV = 11 - residuo, sino DV = residuo
     *
     * @param string $nit NIT sin dígito de verificación (solo números)
     * @return int Dígito de verificación (0-9)
     *
     * @example
     * NitHelper::calcularDigitoVerificacion('900373123') // Returns: 0
     * NitHelper::calcularDigitoVerificacion('890903407') // Returns: 9
     */
    public static function calcularDigitoVerificacion(string $nit): int
    {
        // Limpiar el NIT (solo números)
        $nit = preg_replace('/[^0-9]/', '', $nit);

        if ($nit === '' || $nit === '0') {
            return 0;
        }

        // Invertir el NIT para aplicar los pesos
        $nitInvertido = strrev($nit);
        $suma = 0;
        $longitudNit = strlen($nitInvertido);

        // Multiplicar cada dígito por su peso correspondiente y sumar
        for ($i = 0; $i < $longitudNit; $i++) {
            $digito = (int) $nitInvertido[$i];
            $peso = self::PESOS[$i] ?? 0; // Si el NIT es muy largo, peso = 0
            $suma += $digito * $peso;
        }

        // Calcular residuo
        $residuo = $suma % 11;

        // Calcular dígito de verificación
        return ($residuo > 1) ? (11 - $residuo) : $residuo;
    }

    /**
     * Formatea un NIT con su dígito de verificación.
     *
     * @param string $nit NIT sin dígito de verificación
     * @param string $separator Separador entre NIT y DV (default: '-')
     * @return string NIT formateado (ej: '900373123-0')
     *
     * @example
     * NitHelper::formatearConDV('900373123') // Returns: '900373123-0'
     * NitHelper::formatearConDV('890903407', '') // Returns: '8909034079'
     */
    public static function formatearConDV(string $nit, string $separator = '-'): string
    {
        $nitLimpio = preg_replace('/[^0-9]/', '', $nit);
        $dv = self::calcularDigitoVerificacion($nitLimpio);

        return $nitLimpio . $separator . $dv;
    }

    /**
     * Concatena NIT con dígito de verificación (sin separador).
     *
     * Utilizado para generar composite keys en cruces de datos.
     *
     * @param string $nit NIT sin dígito de verificación
     * @return string NIT + DV concatenado (ej: '9003731230')
     *
     * @example
     * NitHelper::concatenarConDV('900373123') // Returns: '9003731230'
     */
    public static function concatenarConDV(string $nit): string
    {
        return self::formatearConDV($nit, '');
    }

    /**
     * Valida si un dígito de verificación es correcto para un NIT dado.
     *
     * @param string $nit NIT sin dígito de verificación
     * @param int|string $dvProporcionado Dígito de verificación a validar
     * @return bool True si el DV es correcto
     *
     * @example
     * NitHelper::validarDigitoVerificacion('900373123', 0) // Returns: true
     * NitHelper::validarDigitoVerificacion('900373123', 5) // Returns: false
     */
    public static function validarDigitoVerificacion(string $nit, int|string $dvProporcionado): bool
    {
        $dvCalculado = self::calcularDigitoVerificacion($nit);
        return $dvCalculado === (int) $dvProporcionado;
    }
}
