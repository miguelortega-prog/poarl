# Fix: Importación CSV con PostgreSQL COPY - 2025-10-03

## 🎯 Objetivo
Reemplazar importaciones lentas por chunks con PostgreSQL COPY nativo + Go streaming para archivos de 190-300MB.

## 🐛 Problemas Encontrados y Solucionados

### 1. Error: Mismatch de columnas BASCAR (58 cols vs 10 cols)
**Causa:** CSV tiene todas las columnas del Excel, pero tabla esperaba estructura híbrida.

**Solución:** Agreg transformación CSV→JSON en `LoadCsvDataSourcesStep.php`:
- Extrae 3 columnas específicas: `num_tomador`, `fecha_inicio_vig`, `valor_total_fact`
- Resto de columnas → campo `data` (JSONB)

### 2. Error: Formato numérico inválido ` 1.296.926 `
**Causa:** VALOR_TOTAL_FACT con separadores de miles (puntos) y espacios.

**Solución:**  Sanitización numérica en `LoadCsvDataSourcesStep.php` línea 360-368:
```php
$valorTotal = $row[$columnIndex['VALOR_TOTAL_FACT']] ?? null;
if ($valorTotal !== null) {
    $valorTotal = trim(str_replace('.', '', $valorTotal));
    if ($valorTotal === '') {
        $valorTotal = null;
    }
}
```

### 3. Error: `Escape sequence "\I" is invalid` en JSON
**Causa:** PostgreSQL COPY CSV interpreta backslashes como escape sequences por defecto.

**Solución:** Actualizar `PostgreSQLCopyImporter.php` línea 74:
```php
"COPY %s (%s) FROM STDIN WITH (FORMAT csv, DELIMITER '%s', QUOTE '\"', ESCAPE '\"', %s, NULL '')"
```

**Por qué funciona:** `ESCAPE '\"'` (igual a QUOTE) indica a PostgreSQL que use el estándar CSV donde solo las comillas dobles se escapan duplicándolas, NO backslashes.

## 📝 Archivos Modificados

### 1. `app/UseCases/Recaudo/Comunicados/Steps/LoadCsvDataSourcesStep.php`
- ✅ Creado (reemplaza LoadDataSourceFilesStep)
- ✅ Transformación CSV→JSON para BASCAR, BAPRPO, DATPOL
- ✅ Sanitización de valores numéricos
- ✅ Uso de `json_encode()` sin JSON_UNESCAPED_UNICODE

### 2. `app/UseCases/Recaudo/Comunicados/Steps/LoadExcelCSVsStep.php`
- ✅ Creado
- ✅ Transformación CSV→JSON para DETTRA, PAGPLA
- ✅ COPY directo para PAGAPL
- ✅ Uso de `json_encode()` sin JSON_UNESCAPED_UNICODE

### 3. `app/Services/Recaudo/PostgreSQLCopyImporter.php`
- ✅ Agregado `QUOTE '\"', ESCAPE '\"'` en comando COPY
- ✅ Previene interpretación de backslashes como escapes

### 4. `app/UseCases/Recaudo/Comunicados/Processors/ConstitucionMoraAportantesProcessor.php`
- ✅ Reemplazado `LoadDataSourceFilesStep` → `LoadCsvDataSourcesStep`
- ✅ Pipeline actualizado

## 🎊 Resultado Final

**Pipeline optimizado:**
1. **Paso 1:** Cargar CSVs directos con COPY (BASCAR, BAPRPO, DATPOL)
2. **Paso 2:** Convertir Excel a CSV con Go streaming (DETTRA, PAGAPL, PAGPLA)
3. **Paso 3:** Cargar CSVs generados con COPY + transformación
4. **Paso 4+:** Validación y transformaciones SQL

**Performance esperada:**
- CSV 50K filas: 1-3s (vs 30-60s con chunks)
- Excel 2.6M filas: ~5min (vs timeout)
- **Pipeline completo: ~15-25 min** (vs 2-3 horas)

## 🔑 Lecciones Aprendidas

1. **PostgreSQL COPY CSV mode** usa backslash como escape por defecto
2. **Solución:** Configurar `ESCAPE igual a QUOTE` para modo CSV estándar
3. **Valores numéricos:** Sanitizar SIEMPRE antes de importar
4. **JSON encoding:** `json_encode()` sin flags es más seguro para COPY

---

**Estado:** ✅ LISTO PARA PROBAR
**Siguiente paso:** Ejecutar prueba end-to-end completa
