# Análisis del Pipeline de Procesamiento - Comunicados de Recaudo
**Fecha:** 2025-10-05
**Estado:** Importación de datos ✅ completada - Procesamiento SQL ⏳ pendiente

---

## 📊 Resumen Ejecutivo

### Estado Actual del Run #2
- **ID:** 2
- **Tipo:** CONSTITUCIÓN EN MORA - APORTANTES
- **Procesador:** `constitucion_mora_aportantes`
- **Estado:** `failed` (por error anterior, pero datos ya importados correctamente)
- **Periodo:** 202508

### Importación de Datos ✅ COMPLETADA

**Total de registros importados: 8,930,819**

| Data Source | Registros | Archivo | Método de Importación |
|-------------|-----------|---------|----------------------|
| **BASCAR** | 255,178 | CSV (58 cols) | ResilientCsvImporter (línea por línea) |
| **BAPRPO** | 216,589 | CSV (2 cols) | ResilientCsvImporter |
| **DATPOL** | 0 ⚠️ | CSV (45 cols) | ResilientCsvImporter (98.9% errores) |
| **DETTRA** | 1,253,188 | Excel 202.87 MB | Go + PostgreSQL COPY |
| **PAGAPL** | 4,241,458 | Excel 190.91 MB | Go + PostgreSQL COPY (normalizado) |
| **PAGPLA** | 2,964,406 | Excel 289.01 MB | Go + PostgreSQL COPY (normalizado) |

**⚠️ PROBLEMA CRÍTICO:** DATPOL tiene 0 registros importados exitosamente (67,635 errores de 68,406 registros). Este data source es requerido por el procesador.

---

## 🔄 Arquitectura del Pipeline

### 1. Job de Entrada: `ProcessCollectionRunValidation`

**Responsabilidad:** Validar archivos y disparar jobs de carga

**Flujo:**
```
ProcessCollectionRunValidation (queue: validation)
    ↓
    Valida archivos con CollectionRunValidationService
    ↓
    Si validación OK → Crea Batch de jobs de carga:
        - LoadCsvDataSourcesJob (para BASCAR, BAPRPO, DATPOL)
        - LoadExcelWithCopyJob x3 (DETTRA, PAGAPL, PAGPLA)
    ↓
    Cuando TODOS los jobs del batch completan exitosamente:
        → Dispara ProcessCollectionDataJob
```

**Estado:** ✅ Implementado y funcional

**Archivo:** `app/Jobs/ProcessCollectionRunValidation.php`

---

### 2. Jobs de Carga de Datos

#### 2.1 `LoadCsvDataSourcesJob` ✅

**Responsabilidad:** Cargar archivos CSV (BASCAR, BAPRPO, DATPOL) con manejo de errores

**Método:** `ResilientCsvImporter` (línea por línea con chunks de 1000)

**Características:**
- Procesa línea por línea sin detener el proceso ante errores
- Registra errores en tabla `csv_import_error_logs`
- Performance: ~25,000-30,000 filas/segundo

**Queue:** `csv-loading`

**Archivo:** `app/Jobs/LoadCsvDataSourcesJob.php`

**Resultados Run #2:**
- BASCAR: 255,178 registros (0 errores) ✅
- BAPRPO: 216,589 registros (0 errores) ✅
- DATPOL: 0 registros (67,635 errores - 98.9% fallas) ❌

#### 2.2 `LoadExcelWithCopyJob` ✅

**Responsabilidad:** Convertir Excel a CSV con Go y cargar con PostgreSQL COPY

**Flujo:**
```
1. GoExcelConverter: Convierte Excel → CSVs separados por sheet (~40-50 MB/s)
2. normalizeCSV(): Normaliza CSVs para que tengan todas las columnas de la tabla
3. addRunIdToCSV(): Agrega columna run_id al inicio de cada CSV
4. PostgreSQLCopyImporter: Importa con COPY FROM STDIN (ultra-rápido)
5. Limpia CSVs temporales
```

**Performance:**
- Conversión Go: ~40-50 MB/s
- COPY: ~4,000-5,000 filas/segundo
- Total: ~15-20 minutos para 8.4M registros

**Queue:** `collection-notices` (configurado en constructor)

**Archivo:** `app/Jobs/LoadExcelWithCopyJob.php`

**Mejoras Implementadas (2025-10-05):**
- ✅ Método `normalizeCSV()`: Maneja hojas con estructuras diferentes
- ✅ Método `addRunIdToCSV()`: Agrega run_id antes del COPY
- ✅ Método `getTableColumns()`: Excluye id, run_id, created_at
- ✅ Usa `explode()` en lugar de `str_getcsv()` para evitar problemas con comillas

**Resultados Run #2:**
- DETTRA: 1,253,188 registros (2 sheets) ✅
- PAGAPL: 4,241,458 registros (4 sheets con estructuras diferentes) ✅
- PAGPLA: 2,964,406 registros ✅

---

### 3. Job de Procesamiento SQL: `ProcessCollectionDataJob` ⏳

**Responsabilidad:** Ejecutar pipeline de transformación SQL usando el procesador correspondiente

**Flujo:**
```
ProcessCollectionDataJob (queue: processing)
    ↓
    Resuelve procesador desde run.type.processor_type
    ↓
    Ejecuta processor.process(run)
        ↓
        Ejecuta cada step del pipeline en secuencia
    ↓
    Notifica éxito/fallo
```

**Timeout:** 1800s (30 minutos)

**Memory Limit:** 2048M

**Queue:** `processing`

**Archivo:** `app/Jobs/ProcessCollectionDataJob.php`

**Estado:** ✅ Implementado - ⏳ No ejecutado aún para run #2

---

### 4. Procesador: `ConstitucionMoraAportantesProcessor`

**Responsabilidad:** Define y ejecuta el pipeline de steps para comunicados de "Constitución en Mora - Aportantes"

**Data Sources Requeridos:** BASCAR, PAGAPL, BAPRPO, PAGPLA, DATPOL, DETTRA

**Archivo:** `app/UseCases/Recaudo/Comunicados/Processors/ConstitucionMoraAportantesProcessor.php`

#### Pipeline de Steps Definido:

```php
[
    // === FASE 1: CARGA DE DATOS (YA COMPLETADA POR JOBS ANTERIORES) ===

    // Paso 1: LoadCsvDataSourcesStep ✅ (Ya ejecutado por LoadCsvDataSourcesJob)
    // Paso 2: ConvertExcelToCSVStep ✅ (Ya ejecutado por LoadExcelWithCopyJob)
    // Paso 3: LoadExcelCSVsStep ✅ (Ya ejecutado por LoadExcelWithCopyJob)
    // Paso 4: ValidateDataIntegrityStep ⏳

    // === FASE 2: TRANSFORMACIÓN SQL ===

    // Paso 5: TODO - Depurar tablas ❌ NO IMPLEMENTADO
    // Paso 6: GenerateBascarCompositeKeyStep ✅
    // Paso 7: GeneratePagaplCompositeKeyStep ✅
    // Paso 8: CrossBascarWithPagaplStep ✅
    // Paso 9: RemoveCrossedBascarRecordsStep ✅
    // Paso 10: TODO - Nuevo cruce ❌ NO IMPLEMENTADO
    // Paso 11: CountDettraWorkersAndUpdateBascarStep ✅
    // Paso 12+: TODO - Pasos subsecuentes ❌ NO IMPLEMENTADOS
]
```

---

## 📋 Steps Implementados vs Pendientes

### ✅ Steps Implementados (11 archivos)

| # | Step | Archivo | Responsabilidad |
|---|------|---------|-----------------|
| 1 | LoadCsvDataSourcesStep | `Steps/LoadCsvDataSourcesStep.php` | Carga CSV con resilient importer |
| 2 | ConvertExcelToCSVStep | `Steps/ConvertExcelToCSVStep.php` | Convierte Excel a CSV con Go |
| 3 | LoadExcelCSVsStep | `Steps/LoadExcelCSVsStep.php` | Carga CSVs generados con COPY |
| 4 | ValidateDataIntegrityStep | `Steps/ValidateDataIntegrityStep.php` | Valida que datos estén en BD |
| - | FilterBascarByPeriodStep | `Steps/FilterBascarByPeriodStep.php` | Filtra BASCAR por periodo |
| 6 | GenerateBascarCompositeKeyStep | `Steps/GenerateBascarCompositeKeyStep.php` | Genera llaves compuestas en BASCAR |
| 7 | GeneratePagaplCompositeKeyStep | `Steps/GeneratePagaplCompositeKeyStep.php` | Genera llaves compuestas en PAGAPL |
| 8 | CrossBascarWithPagaplStep | `Steps/CrossBascarWithPagaplStep.php` | Cruza BASCAR con PAGAPL |
| 9 | RemoveCrossedBascarRecordsStep | `Steps/RemoveCrossedBascarRecordsStep.php` | Elimina registros cruzados de BASCAR |
| 11 | CountDettraWorkersAndUpdateBascarStep | `Steps/CountDettraWorkersAndUpdateBascarStep.php` | Cuenta trabajadores de DETTRA |
| - | LoadDataSourceFilesStep | `Steps/LoadDataSourceFilesStep.php` | (Legacy - ya no se usa) |

### ❌ Steps Pendientes de Implementación

| # | Step | Estado | Prioridad |
|---|------|--------|-----------|
| 5 | Depurar tablas (eliminar registros innecesarios) | TODO comentado en código | Media |
| 10 | Nuevo cruce (pendiente definición) | TODO comentado en código | Desconocida |
| 12+ | Pasos subsecuentes | TODO comentado en código | Desconocida |

---

## ⚠️ Problemas Críticos Identificados

### 1. DATPOL con 0 registros importados 🔴

**Impacto:** El procesador `ConstitucionMoraAportantesProcessor` requiere DATPOL como data source

**Causa:** 67,635 de 68,406 registros (98.9%) fallaron durante importación con `ResilientCsvImporter`

**Tipos de errores registrados en `csv_import_error_logs`:**
- Probablemente: `column_mismatch` o `insert_error`

**Soluciones posibles:**
1. Analizar logs de error: `SELECT * FROM csv_import_error_logs WHERE data_source_code = 'DATPOL' AND run_id = 2 LIMIT 10`
2. Verificar estructura del CSV vs tabla `data_source_datpol`
3. Posible problema: caracteres especiales, encoding, delimitador incorrecto
4. Considerar usar el mismo enfoque de normalización que PAGAPL/PAGPLA

**Prioridad:** 🔴 CRÍTICA - Debe resolverse antes de ejecutar `ProcessCollectionDataJob`

### 2. Steps Faltantes en el Pipeline ⚠️

**Paso 5:** Depurar tablas (eliminar registros innecesarios)
- **Estado:** TODO comentado en código
- **Impacto:** Sin este paso, se procesarán TODOS los registros (8.9M), lo cual puede ser innecesario
- **Pregunta clave:** ¿Qué registros deben depurarse? ¿Filtros por periodo? ¿Por estado?

**Paso 10:** Nuevo cruce (pendiente definición)
- **Estado:** TODO comentado en código
- **Impacto:** Desconocido - falta definición de reglas de negocio

**Paso 12+:** Pasos subsecuentes
- **Estado:** TODO comentado en código
- **Impacto:** Desconocido - falta definición de pipeline completo

**Prioridad:** ⚠️ ALTA - Necesita reunión con negocio para definir

### 3. Conflicto entre Jobs y Steps 🟡

**Observación:** La Fase 1 del procesador (Steps 1-3) intenta cargar datos, pero estos ya fueron cargados por los jobs `LoadCsvDataSourcesJob` y `LoadExcelWithCopyJob`.

**Implicación:**
- Si se ejecuta `ProcessCollectionDataJob`, los steps 1-3 intentarán cargar los datos nuevamente
- Esto podría causar duplicación de datos o errores

**Posibles soluciones:**
1. **Opción A:** Modificar los steps 1-3 para que verifiquen si los datos ya fueron cargados (por `run_id`)
2. **Opción B:** Eliminar steps 1-3 del procesador y asumir que los datos ya están en BD
3. **Opción C:** Agregar flag en `CollectionNoticeRun` indicando que datos ya fueron cargados

**Recomendación:** Opción A - Los steps deben ser idempotentes

**Prioridad:** 🟡 MEDIA - Debe resolverse antes de ejecutar `ProcessCollectionDataJob`

---

## 🎯 Estado de los Steps del Pipeline

### FASE 1: Carga de Datos ✅ (Completada por Jobs)

**Nota:** Estos steps existen en el procesador pero ya fueron ejecutados por los jobs de carga

| Step | Estado | Responsable | Resultado |
|------|--------|-------------|-----------|
| 1. Cargar CSV | ✅ Completado | `LoadCsvDataSourcesJob` | BASCAR: 255K, BAPRPO: 216K, DATPOL: 0 ❌ |
| 2. Convertir Excel | ✅ Completado | `LoadExcelWithCopyJob` | 3 archivos convertidos |
| 3. Cargar CSV generados | ✅ Completado | `LoadExcelWithCopyJob` | DETTRA: 1.2M, PAGAPL: 4.2M, PAGPLA: 2.9M |
| 4. Validar integridad | ⏳ Pendiente | `ValidateDataIntegrityStep` | No ejecutado aún |

### FASE 2: Transformación SQL ⏳ (Pendiente)

| Step | Estado | Implementado | Listo para Ejecutar |
|------|--------|--------------|---------------------|
| 5. Depurar tablas | ❌ TODO | NO | NO |
| 6. Generar keys BASCAR | ✅ | SÍ | ⏳ Depende de paso 4 |
| 7. Generar keys PAGAPL | ✅ | SÍ | ⏳ Depende de paso 4 |
| 8. Cruzar BASCAR-PAGAPL | ✅ | SÍ | ⏳ Depende de pasos 6-7 |
| 9. Eliminar cruzados BASCAR | ✅ | SÍ | ⏳ Depende de paso 8 |
| 10. Nuevo cruce | ❌ TODO | NO | NO |
| 11. Contar trabajadores DETTRA | ✅ | SÍ | ⏳ Depende de pasos anteriores |
| 12+. Pasos subsecuentes | ❌ TODO | NO | NO |

---

## 📐 Arquitectura de Datos

### Tablas de Data Sources (particionadas por run_id)

| Tabla | Columnas | Índices | Registros (run #2) |
|-------|----------|---------|-------------------|
| `data_source_bascar` | 58 (56 datos + run_id + created_at) | run_id, num_tomador, num_poliza | 255,178 |
| `data_source_baprpo` | 4 (2 datos + run_id + created_at) | run_id | 216,589 |
| `data_source_datpol` | 47 (45 datos + run_id + created_at) | run_id, num_poli, nro_documto | 0 ❌ |
| `data_source_dettra` | 43 (40 datos + run_id + created_at) | run_id, num_poli, nro_documto | 1,253,188 |
| `data_source_pagapl` | 21 (19 datos + run_id + created_at) | run_id, poliza, identifi | 4,241,458 |
| `data_source_pagpla` | 21 (19 datos + run_id + created_at) | run_id, poliza, identificacion | 2,964,406 |

**Total:** 8,930,819 registros

### Tabla de Errores de Importación

| Tabla | Propósito | Registros (run #2) |
|-------|-----------|-------------------|
| `csv_import_error_logs` | Registra errores de importación CSV | ~67,635 (DATPOL) |

**Columnas:**
- `run_id`, `data_source_code`, `table_name`, `line_number`
- `line_content`, `error_type`, `error_message`, `created_at`

---

## ⏱️ Tiempos Estimados

### Fase 1: Importación de Datos ✅ (Completada)

| Tarea | Tiempo Estimado | Tiempo Real |
|-------|-----------------|-------------|
| Validación archivos | 1-2 min | ✅ |
| Carga CSV (BASCAR, BAPRPO, DATPOL) | 2-3 min | ✅ ~2-3 min |
| Conversión Excel a CSV (Go) | 8-10 min | ✅ ~8 min |
| Carga Excel con COPY | 5-7 min | ✅ ~6 min |
| **TOTAL FASE 1** | **~20-25 min** | **✅ ~20 min** |

### Fase 2: Procesamiento SQL ⏳ (Pendiente)

| Tarea | Complejidad | Tiempo Estimado |
|-------|-------------|-----------------|
| Validar integridad | Baja | 1-2 min |
| Generar composite keys | Media | 3-5 min |
| Cruces y transformaciones | Alta | 10-20 min |
| Generar archivos salida | Media | 5-10 min |
| **TOTAL FASE 2** | - | **~20-40 min** |

### **TIEMPO TOTAL PIPELINE:** ~40-65 minutos

---

## 🚦 Próximos Pasos Recomendados

### 1. 🔴 URGENTE: Resolver problema de DATPOL

**Acción:**
```bash
# Analizar primeros 10 errores
docker-compose exec poarl-php php artisan tinker --execute="
\$errors = DB::table('csv_import_error_logs')
    ->where('data_source_code', 'DATPOL')
    ->where('run_id', 2)
    ->limit(10)
    ->get();

foreach (\$errors as \$error) {
    echo 'Línea ' . \$error->line_number . ': ' . \$error->error_type . PHP_EOL;
    echo '  Error: ' . \$error->error_message . PHP_EOL;
    echo '  Contenido: ' . substr(\$error->line_content, 0, 100) . '...' . PHP_EOL . PHP_EOL;
}
"
```

**Decisión:**
- ¿DATPOL es realmente requerido?
- Si sí: Corregir importación y volver a cargar
- Si no: Modificar `canProcess()` del procesador

### 2. 🟡 Revisar conflicto Steps 1-3

**Acción:** Modificar steps de carga para verificar si datos ya existen antes de cargar

**Código sugerido:**
```php
public function execute(CollectionNoticeRun $run): void
{
    // Verificar si datos ya fueron cargados
    $count = DB::table($this->tableName)->where('run_id', $run->id)->count();

    if ($count > 0) {
        Log::info('Datos ya cargados para este run, omitiendo step', [
            'step' => static::class,
            'run_id' => $run->id,
            'existing_records' => $count,
        ]);
        return;
    }

    // Proceder con carga...
}
```

### 3. ⚠️ Definir Steps Faltantes

**Reunión requerida con negocio para definir:**
- Paso 5: ¿Qué registros depurar? ¿Criterios de filtrado?
- Paso 10: ¿Qué tipo de cruce se necesita?
- Paso 12+: ¿Qué pasos subsecuentes faltan?

### 4. ✅ Ejecutar Pipeline Completo

**Una vez resueltos los puntos anteriores:**

```bash
# Actualizar estado del run a 'validated'
docker-compose exec poarl-php php artisan tinker --execute="
\$run = \App\Models\CollectionNoticeRun::find(2);
\$run->update(['status' => 'validated']);
echo 'Run actualizado a validated' . PHP_EOL;
"

# Disparar ProcessCollectionDataJob
docker-compose exec poarl-php php artisan tinker --execute="
\App\Jobs\ProcessCollectionDataJob::dispatch(2);
echo 'Job de procesamiento despachado' . PHP_EOL;
"

# Monitorear logs
docker-compose logs -f --tail=100 poarl-php
```

---

## 📝 Notas Técnicas Importantes

### Idempotencia
- Los jobs y steps deben ser idempotentes
- Deben poder ejecutarse múltiples veces sin duplicar datos
- Usar `run_id` como clave de partición para evitar conflictos

### Manejo de Errores
- `LoadCsvDataSourcesJob`: Resiliente, registra errores pero continúa
- `LoadExcelWithCopyJob`: Falla completo si hay error en COPY
- `ProcessCollectionDataJob`: Falla y notifica si cualquier step falla

### Performance
- Importación CSV resiliente: ~25K-30K filas/seg
- Conversión Go: ~40-50 MB/s
- PostgreSQL COPY: ~4K-5K filas/seg
- Total para 8.9M registros: ~20-25 minutos

### Memoria
- `LoadExcelWithCopyJob`: Bajo uso de memoria (streaming)
- `ProcessCollectionDataJob`: 2GB memory_limit para operaciones SQL pesadas

---

## 🔍 Comandos Útiles para Debugging

### Verificar estado de tablas
```bash
docker-compose exec poarl-php php artisan tinker --execute="
\$tables = ['data_source_bascar', 'data_source_baprpo', 'data_source_datpol',
           'data_source_dettra', 'data_source_pagapl', 'data_source_pagpla'];

foreach (\$tables as \$table) {
    \$count = DB::table(\$table)->where('run_id', 2)->count();
    echo \$table . ': ' . number_format(\$count) . PHP_EOL;
}
"
```

### Ver errores de importación
```bash
docker-compose exec poarl-php php artisan tinker --execute="
\$errors = DB::table('csv_import_error_logs')
    ->where('run_id', 2)
    ->select('data_source_code', DB::raw('COUNT(*) as total'))
    ->groupBy('data_source_code')
    ->get();

foreach (\$errors as \$error) {
    echo \$error->data_source_code . ': ' . number_format(\$error->total) . ' errores' . PHP_EOL;
}
"
```

### Verificar batch jobs
```bash
docker-compose exec poarl-php php artisan tinker --execute="
\$batch = DB::table('job_batches')->latest('id')->first();
echo 'Batch: ' . \$batch->name . PHP_EOL;
echo 'Total: ' . \$batch->total_jobs . PHP_EOL;
echo 'Pending: ' . \$batch->pending_jobs . PHP_EOL;
echo 'Failed: ' . \$batch->failed_jobs . PHP_EOL;
"
```

---

## 📚 Archivos Clave del Sistema

### Jobs
- `app/Jobs/ProcessCollectionRunValidation.php` - Job principal de entrada
- `app/Jobs/LoadCsvDataSourcesJob.php` - Carga CSV resiliente
- `app/Jobs/LoadExcelWithCopyJob.php` - Carga Excel optimizada
- `app/Jobs/ProcessCollectionDataJob.php` - Procesamiento SQL

### Procesadores
- `app/UseCases/Recaudo/Comunicados/Processors/ConstitucionMoraAportantesProcessor.php`
- `app/Services/Recaudo/Comunicados/BaseCollectionNoticeProcessor.php`
- `app/Contracts/Recaudo/Comunicados/CollectionNoticeProcessorInterface.php`

### Steps (11 implementados)
- `app/UseCases/Recaudo/Comunicados/Steps/*.php`

### Servicios
- `app/Services/Recaudo/GoExcelConverter.php` - Conversión Excel con Go
- `app/Services/Recaudo/PostgreSQLCopyImporter.php` - Importación con COPY
- `app/Services/Recaudo/ResilientCsvImporter.php` - Importación CSV resiliente
- `app/Services/CollectionRun/CollectionRunValidationService.php` - Validación

### Migraciones
- `database/migrations/2025_10_04_134230_recreate_data_source_tables_with_all_columns.php` - CSV tables
- `database/migrations/2025_10_05_005943_recreate_excel_data_source_tables_with_all_columns.php` - Excel tables
- `database/migrations/create_csv_import_error_logs_table.php` - Error logs

---

## ✅ Conclusiones

### Lo que está funcionando bien:
1. ✅ Importación de archivos Excel con Go + COPY (ultra-rápida)
2. ✅ Normalización de CSVs con estructuras variables
3. ✅ Manejo resiliente de errores en CSV
4. ✅ Arquitectura de jobs en batch
5. ✅ Steps SQL implementados (6 de ~12+)

### Lo que necesita atención:
1. 🔴 DATPOL con 0 registros (CRÍTICO)
2. 🟡 Conflicto entre jobs y steps de carga
3. ⚠️ Steps faltantes (5, 10, 12+) necesitan definición de negocio
4. ⏳ Pipeline SQL nunca se ha ejecutado completo

### Tiempo total estimado del pipeline:
**40-65 minutos** (20-25 min importación + 20-40 min procesamiento SQL)

Este tiempo es tolerable considerando el volumen de ~9M registros procesados.
