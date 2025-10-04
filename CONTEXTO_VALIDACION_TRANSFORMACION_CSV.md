# Contexto: Validación Transformación CSV→JSON para DETTRA y PAGPLA

**Fecha:** 2025-10-03
**Branch:** `feat/implements_job_for_procesing_data_sources`

## 🎯 Problema Detectado

En la prueba del flujo completo Go Streaming + PostgreSQL COPY, se detectó un **mismatch entre estructura CSV y estructura de tabla**:

### Error Encontrado
```
ERROR: column "num_trabajador" of relation "data_source_dettra" does not exist
```

### Causa Raíz
El binario Go genera CSVs con **todas las columnas del Excel separadas**:
```csv
ACTI_RIES;CPOS_RIES;KEY;COD_RIES;NUM_POLI;NIT;...;sheet_name
valor1;valor2;valor3;valor4;...;Base
```

Pero las tablas DETTRA y PAGPLA tienen estructura **simplificada con JSONB**:
```sql
CREATE TABLE data_source_dettra (
    id BIGINT,
    run_id INTEGER,
    data JSONB,           -- ← Todo el row en JSON
    created_at TIMESTAMP,
    sheet_name VARCHAR
);
```

## ✅ Solución Implementada

### 1. Transformación CSV en Tiempo de Importación

**Archivo modificado:** `app/UseCases/Recaudo/Comunicados/Steps/LoadExcelCSVsStep.php`

**Nuevo método agregado:** `transformCsvToJsonFormat()`

**Flujo:**
1. Lee CSV original con todas las columnas
2. Lee header para obtener nombres de columnas
3. Por cada fila:
   - Construye objeto JSON con todos los campos
   - Genera nuevo CSV con formato: `run_id;{json_data};sheet_name`
4. Importa CSV transformado con PostgreSQL COPY

**Ejemplo transformación:**

**CSV Original (39 columnas):**
```csv
ACTI_RIES;CPOS_RIES;KEY;COD_RIES;NUM_POLI;NIT;...;sheet_name
001;002;ABC;003;12345;987654;...;Base
```

**CSV Transformado (3 columnas):**
```csv
run_id;data;sheet_name
1;"{\"ACTI_RIES\":\"001\",\"CPOS_RIES\":\"002\",\"KEY\":\"ABC\",...}";Base
```

### 2. Data Sources Afectados

**Necesitan transformación:**
- `DETTRA` - Detalle Trabajadores
- `PAGPLA` - Pagos Planilla

**NO necesitan transformación:**
- `PAGAPL` - Pagos Aplicados (tiene columnas individuales: identificacion, periodo, valor)

### 3. Cambios en LoadExcelCSVsStep.php

**Constante agregada:**
```php
private const NEEDS_TRANSFORMATION = ['DETTRA', 'PAGPLA'];
```

**Lógica en execute():**
```php
// Antes de importar cada CSV
if (in_array($dataSourceCode, self::NEEDS_TRANSFORMATION, true)) {
    Log::info('🔄 Transformando CSV (columnas→JSON) antes de COPY');

    $finalCsvPath = $this->transformCsvToJsonFormat(
        $csvPath,
        $run->id,
        $sheetName
    );
}

// Importar CSV transformado (o el original si no necesita transformación)
$result = $this->copyImporter->importFromFile(
    $tableName,
    $finalCsvPath,  // ← Ruta del CSV transformado
    $columns,
    ';',
    true
);
```

## 📋 Pipeline Completo Actualizado

**FASE 1: CARGA DE DATOS**

1. **Paso 1:** Cargar CSVs directos (BASCAR, BAPRPO, DATPOL) con PostgreSQL COPY
   - ✅ Funcional

2. **Paso 2:** Convertir Excel a CSV con Go streaming (DETTRA, PAGAPL, PAGPLA) - CRÍTICO
   - ✅ Funcional
   - Duración probada: ~15-20 minutos para 3 archivos de 190-300MB

3. **Paso 3:** Cargar CSVs generados con PostgreSQL COPY
   - ✅ Implementada transformación CSV→JSON
   - 🧪 EN PRUEBA AHORA
   - Para DETTRA y PAGPLA: transforma CSV antes de COPY
   - Para PAGAPL: usa CSV directo (sin transformación)

4. **Paso 4:** Validar integridad de datos
   - ⏳ Pendiente de ejecutar

**FASE 2: TRANSFORMACIÓN SQL**

5. Paso 5: TODO - Depurar tablas
6. Paso 6: Generar llaves compuestas en BASCAR
7. Paso 7: Generar llaves compuestas en PAGAPL
8. Paso 8: Cruzar BASCAR con PAGAPL
9. Paso 9: Eliminar registros cruzados de BASCAR
10. Paso 10: TODO - Nuevo cruce
11. Paso 11: Contar trabajadores DETTRA

## 🧪 Prueba en Curso

### Estado Actual
- ✅ Base de datos limpia
- ✅ Run #1 reseteado a status 'pending'
- ✅ Contenedores reiniciados con nuevo código
- 🔄 Job ejecutándose en background (bash_id: da7cd3)

### Comando Ejecutado
```bash
docker-compose exec poarl-php php artisan tinker --execute="
\$job = new \App\Jobs\ProcessCollectionNoticeRunData(1);
\$notificationService = app(\App\Services\NotificationService::class);
\$job->handle(\$notificationService);
"
```

### Qué Esperamos Ver en Logs

**Para DETTRA y PAGPLA:**
```
🔄 Transformando CSV (columnas→JSON) antes de COPY
✅ CSV transformado a formato JSON
   - rows_processed: XXXXX
📄 Importando CSV con COPY
✅ CSV importado con COPY
   - rows_imported: XXXXX
```

**Para PAGAPL:**
```
📄 Importando CSV con COPY (sin transformación)
✅ CSV importado con COPY
```

## 📊 Performance Esperada

### Paso 2: Conversión Excel→CSV (Go Streaming)
- PAGAPL (191MB): ~3-4 minutos
- PAGPLA (289MB): ~4-5 minutos
- DETTRA (203MB): ~4-5 minutos
- **Total Paso 2:** ~12-15 minutos

### Paso 3: Transformación + COPY
**Transformación CSV→JSON (PHP):**
- DETTRA: ~X minutos (por determinar en esta prueba)
- PAGPLA: ~X minutos (por determinar en esta prueba)

**PostgreSQL COPY:**
- Extremadamente rápido: ~segundos por archivo
- 10-50x más rápido que chunks

### Total Pipeline Completo
- **Estimado:** 15-25 minutos (dependiendo de transformación)
- **Antes (chunks):** ~2-3 horas

## 🔍 Validaciones de la Prueba

### ✅ Debe funcionar:
1. Paso 1: CSVs directos se importan correctamente
2. Paso 2: Go convierte 3 archivos Excel sin errores de memoria
3. Paso 3:
   - DETTRA se transforma y se importa sin error de columnas
   - PAGPLA se transforma y se importa sin error de columnas
   - PAGAPL se importa directo sin transformación
4. Paso 4: Validación confirma que todos los archivos tienen datos

### ❌ Si falla:
- Revisar logs de transformación
- Verificar formato JSON generado
- Confirmar que CSV transformado tiene estructura correcta

## 📝 Archivos Modificados en Esta Sesión

### Modificados:
1. `app/UseCases/Recaudo/Comunicados/Steps/LoadExcelCSVsStep.php`
   - Agregado método `transformCsvToJsonFormat()`
   - Agregado constante `NEEDS_TRANSFORMATION`
   - Modificado `execute()` para aplicar transformación cuando sea necesario

### Eliminados (en sesión anterior):
1. `app/UseCases/Recaudo/Comunicados/Steps/LoadDettraAllSheetsStep.php` ❌
2. `app/UseCases/Recaudo/Comunicados/Steps/LoadPagaplSheetByPeriodStep.php` ❌

### Sin cambios (solo lectura):
- `app/Services/Recaudo/GoExcelConverter.php`
- `app/Services/Recaudo/PostgreSQLCopyImporter.php`
- `app/UseCases/Recaudo/Comunicados/Steps/ConvertExcelToCSVStep.php`
- `app/UseCases/Recaudo/Comunicados/Processors/ConstitucionMoraAportantesProcessor.php`

## 🎯 Objetivo de Esta Validación

**Confirmar que el flujo completo funciona end-to-end:**

```
┌─────────────────────────────────────────────────────────────┐
│ FLUJO OPTIMIZADO GO STREAMING + POSTGRESQL COPY             │
└─────────────────────────────────────────────────────────────┘

1. Subir archivos
   ↓
2. Paso 1: COPY directo (CSV files)
   ↓
3. Paso 2: Go Streaming (Excel → CSV)
   - DETTRA: 203MB → CSV 39 columnas
   - PAGAPL: 191MB → CSV con columnas específicas
   - PAGPLA: 289MB → CSV 39 columnas
   ↓
4. Paso 3: Transform + COPY
   - DETTRA: CSV 39 cols → CSV 3 cols (JSON) → COPY
   - PAGPLA: CSV 39 cols → CSV 3 cols (JSON) → COPY
   - PAGAPL: CSV directo → COPY (sin transformación)
   ↓
5. Paso 4: Validar integridad
   ↓
6. Pasos 5-11: SQL transformations
   ↓
7. ✅ SUCCESS
```

## 🚀 Próximos Pasos (si la prueba pasa)

1. ✅ Commit de cambios
2. 📊 Documentar métricas de performance reales
3. 🔧 Optimizar transformación si es necesario (¿mover a Go?)
4. 📋 Implementar Paso 5 (Cleanup tables)
5. 🎉 Celebrar que finalmente funciona después de tanto trabajo

---

**Estado:** 🔄 PRUEBA EN EJECUCIÓN

**Monitoreo:** Background bash da7cd3 y 411e9f
