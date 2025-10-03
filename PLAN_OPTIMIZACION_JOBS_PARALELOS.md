# Plan de Optimización - Jobs Paralelos para Carga de Data Sources
**Fecha:** 2025-10-02
**Estrategia:** Opción A - Jobs en Paralelo por Tipo de Archivo

---

## 📊 Contexto y Problema

### Análisis de Performance Actual (Run #8)

| Paso | Duración | % del Total | Tipo |
|------|----------|-------------|------|
| 1. Cargar archivos de insumos | 108.8s (1.8 min) | 11.2% | I/O Excel |
| 2. Validar integridad | 0.015s | 0.002% | SQL |
| 3. Filtrar BASCAR por periodo | 18.6s | 1.9% | SQL |
| 4. Generar composite keys BASCAR | 21.6s | 2.2% | SQL |
| **5. Cargar hoja PAGAPL** | **772.7s (12.9 min)** | **79.5%** 🔴 | I/O Excel |
| 6. Generar composite keys PAGAPL | 35.4s | 3.6% | SQL |
| **7. Cruzar BASCAR con PAGAPL** | **7.3s** ⚡ | 0.7% | SQL optimizado |
| **Total:** | **964s (16 min)** | 100% | |

**Hallazgos clave:**
- 🔴 **91% del tiempo se va en cargar archivos Excel** (pasos 1 y 5)
- ✅ **SQL es extremadamente rápido** (~8.5% del tiempo total)
- ✅ **Cruce optimizado funciona perfecto** (7.3s con tabla temporal)
- ❌ **DETTRA (203 MB) nunca terminó** - el job crasheó

### Archivos de Insumos

**CSV (rápidos):**
- BASCAR - Base cartera
- BAPRPO - Base producción por póliza
- DATPOL - Datos póliza

**XLSX (lentos):**
- PAGAPL - Pagos aplicados (~191 MB) → **772s (12.9 min)**
- DETTRA - Detalle trabajadores (~203 MB) → **estimado 15+ min**
- PAGPLA - Pagos planilla (~289 MB) → **estimado 20+ min**

---

## 🎯 Solución Propuesta: Jobs Paralelos

### Arquitectura Nueva

```
Usuario crea Run
    ↓
[VALIDACIÓN]
ValidateCollectionNoticeRunJob (actual, sin cambios)
    ↓
    ├─→ LoadCsvDataSourcesJob (paralelo, ~30s)
    │   ├─ BASCAR (CSV)
    │   ├─ BAPRPO (CSV)
    │   └─ DATPOL (CSV)
    │
    ├─→ LoadPagaplDataSourceJob (paralelo, ~13min)
    │   └─ Solo hoja del periodo (ej: "2024-2025")
    │
    ├─→ LoadDettraDataSourceJob (paralelo, ~15min)
    │   └─ Todas las hojas (ej: "Base")
    │
    └─→ LoadPagplaDataSourceJob (paralelo, ~20min)
        └─ Hoja del periodo
    ↓
[Esperar que TODOS los jobs de carga completen]
    ↓
[PROCESAMIENTO SQL PURO]
ProcessCollectionDataJob (~2-5min total)
    ├─ Paso 1: Generar composite keys BASCAR (SQL)
    ├─ Paso 2: Filtrar BASCAR por periodo (SQL)
    ├─ Paso 3: Generar composite keys PAGAPL (SQL)
    ├─ Paso 4: Filtrar PAGAPL por periodo (SQL)
    ├─ Paso 5: CrossBascarWithPagaplStep (SQL + tabla temporal) ✅ Ya optimizado
    ├─ Paso 6: CrossBascarWithBaprpoStep (SQL)
    ├─ Paso 7: CrossBascarWithPagplaStep (SQL)
    ├─ Paso 8: CrossBascarWithDatpolStep (SQL)
    ├─ Paso 9: CountDettraWorkersAndUpdateBascarStep (SQL)
    └─ Paso 10: GenerateOutputFilesStep
    ↓
[COMPLETADO] ✅
```

### Tiempo Estimado

**Actual (secuencial):**
- Carga archivos: ~40-50 minutos
- Procesamiento SQL: ~5 minutos
- **Total: ~45-55 minutos**

**Nuevo (paralelo):**
- Carga en paralelo: ~20 minutos (el más lento = PAGPLA)
- Procesamiento SQL: ~5 minutos
- **Total: ~25 minutos** ⚡ **Reducción del 50%**

---

## 🔧 Implementación

### Fase 1: Crear Jobs Especializados

#### 1.1 LoadCsvDataSourcesJob
**Archivo:** `app/Jobs/LoadCsvDataSourcesJob.php`

**Responsabilidades:**
- Cargar BASCAR (CSV)
- Cargar BAPRPO (CSV)
- Cargar DATPOL (CSV)
- **NO generar composite keys** (se hará en ProcessCollectionDataJob)
- **NO filtrar por periodo** (se hará en ProcessCollectionDataJob)
- Solo inserción masiva en tablas

**Tabla destino:**
- `data_source_bascar`
- `data_source_baprpo`
- `data_source_datpol`

**Parámetros:**
- `$runId` - ID del run
- Cola: `csv-loading` (nueva cola)
- Timeout: 300s (5 min)
- Tries: 2

---

#### 1.2 LoadPagaplDataSourceJob
**Archivo:** `app/Jobs/LoadPagaplDataSourceJob.php`

**Responsabilidades:**
- Cargar **solo la hoja del periodo** de PAGAPL
- Ejemplo: Si periodo = "202508" (2025-08), cargar hoja "2024-2025"
- **NO generar composite keys** (se hará en SQL)
- Solo inserción masiva

**Tabla destino:**
- `data_source_pagapl`

**Optimizaciones específicas:**
- Chunk size: 10000 (en vez de 5000)
- Desactivar logs DEBUG
- Batch inserts más grandes

**Parámetros:**
- `$runId` - ID del run
- `$period` - Periodo (ej: "202508")
- Cola: `excel-loading` (nueva cola)
- Timeout: 1200s (20 min)
- Tries: 2

---

#### 1.3 LoadDettraDataSourceJob
**Archivo:** `app/Jobs/LoadDettraDataSourceJob.php`

**Responsabilidades:**
- Cargar **todas las hojas** de DETTRA
- Almacenar en columna JSONB `data`
- Solo inserción masiva

**Tabla destino:**
- `data_source_dettra`

**Optimizaciones específicas:**
- Chunk size: 10000
- Desactivar logs DEBUG
- Batch inserts más grandes

**Parámetros:**
- `$runId` - ID del run
- Cola: `excel-loading` (nueva cola)
- Timeout: 1800s (30 min)
- Tries: 2

---

#### 1.4 LoadPagplaDataSourceJob
**Archivo:** `app/Jobs/LoadPagplaDataSourceJob.php`

**Responsabilidades:**
- Cargar **solo la hoja del periodo** de PAGPLA
- Almacenar en columna JSONB `data`
- Solo inserción masiva

**Tabla destino:**
- `data_source_pagpla`

**Optimizaciones específicas:**
- Chunk size: 10000
- Desactivar logs DEBUG
- Batch inserts más grandes

**Parámetros:**
- `$runId` - ID del run
- `$period` - Periodo
- Cola: `excel-loading` (nueva cola)
- Timeout: 2400s (40 min - es el más grande)
- Tries: 2

---

#### 1.5 ProcessCollectionDataJob
**Archivo:** `app/Jobs/ProcessCollectionDataJob.php`

**Responsabilidades:**
- **TODO en SQL puro, sin lectura de archivos**
- Ejecutar steps del processor **solo para operaciones SQL**

**Steps que ejecuta:**
1. ValidateDataIntegrityStep (verificar que todos los archivos cargaron)
2. FilterBascarByPeriodStep (SQL UPDATE)
3. GenerateBascarCompositeKeyStep (SQL UPDATE)
4. GeneratePagaplCompositeKeyStep (SQL UPDATE)
5. CrossBascarWithPagaplStep ✅ (ya optimizado)
6. RemoveCrossedBascarRecordsStep (SQL DELETE)
7. CountDettraWorkersAndUpdateBascarStep (SQL UPDATE)
8. CrossBascarWithBaprpoStep (SQL - TODO)
9. CrossBascarWithPagplaStep (SQL - TODO)
10. CrossBascarWithDatpolStep (SQL - TODO)
11. GenerateOutputFilesStep (generar CSVs desde BD)

**Parámetros:**
- `$runId` - ID del run
- Cola: `processing` (existente)
- Timeout: 1800s (30 min)
- Tries: 3

---

### Fase 2: Dispatcher con Laravel Bus

**Archivo a modificar:** `app/Jobs/ProcessCollectionRunValidation.php`

**Cambio en línea 96-102:**

```php
// ANTES (código actual):
if ($validationSuccess) {
    Log::info('Disparando job de procesamiento de datos', [
        'run_id' => $run->id,
    ]);

    ProcessCollectionNoticeRunData::dispatch($run->id);
}

// DESPUÉS (nuevo código):
if ($validationSuccess) {
    Log::info('Disparando jobs de carga de data sources en paralelo', [
        'run_id' => $run->id,
    ]);

    // Despachar jobs de carga en paralelo
    Bus::batch([
        new LoadCsvDataSourcesJob($run->id),
        new LoadPagaplDataSourceJob($run->id, $run->period),
        new LoadDettraDataSourceJob($run->id),
        new LoadPagplaDataSourceJob($run->id, $run->period),
    ])
    ->name("Carga de Data Sources - Run #{$run->id}")
    ->then(function (Batch $batch) use ($run) {
        // Cuando TODOS los jobs de carga completen, disparar procesamiento
        Log::info('Todos los archivos cargados, iniciando procesamiento SQL', [
            'run_id' => $run->id,
        ]);

        ProcessCollectionDataJob::dispatch($run->id);
    })
    ->catch(function (Batch $batch, Throwable $e) use ($run) {
        // Si algún job de carga falla
        Log::error('Error en carga de data sources', [
            'run_id' => $run->id,
            'error' => $e->getMessage(),
        ]);

        $run->update([
            'status' => 'failed',
            'failed_at' => now(),
            'errors' => [
                'message' => 'Error durante la carga de archivos',
                'details' => $e->getMessage(),
            ],
        ]);
    })
    ->allowFailures(false) // Si uno falla, detener todo
    ->onQueue('validation')
    ->dispatch();
}
```

---

### Fase 3: Configurar Colas en Horizon

**Archivo a modificar:** `config/horizon.php`

**Agregar nuevas colas:**

```php
'environments' => [
    'production' => [
        'supervisor-validation' => [
            'connection' => 'redis',
            'queue' => ['validation'],
            'balance' => 'auto',
            'processes' => 1,
            'tries' => 3,
        ],

        // NUEVA: Cola para carga de CSV (rápida)
        'supervisor-csv-loading' => [
            'connection' => 'redis',
            'queue' => ['csv-loading'],
            'balance' => 'auto',
            'processes' => 1,
            'tries' => 2,
            'timeout' => 300,
        ],

        // NUEVA: Cola para carga de Excel (lenta, múltiples workers)
        'supervisor-excel-loading' => [
            'connection' => 'redis',
            'queue' => ['excel-loading'],
            'balance' => 'auto',
            'processes' => 3, // 3 workers para procesar 3 Excel en paralelo
            'tries' => 2,
            'timeout' => 2400, // 40 min
        ],

        'supervisor-processing' => [
            'connection' => 'redis',
            'queue' => ['processing'],
            'balance' => 'auto',
            'processes' => 1,
            'tries' => 3,
            'timeout' => 1800,
        ],
    ],
],
```

---

### Fase 4: Refactorizar Steps Existentes

#### Steps que se ELIMINAN del Processor:
- ❌ `LoadDataSourceFilesStep` → Movido a jobs de carga
- ❌ `LoadPagaplSheetByPeriodStep` → Movido a LoadPagaplDataSourceJob

#### Steps que se MANTIENEN (operaciones SQL):
- ✅ `ValidateDataIntegrityStep` → Verifica que datos existan en BD
- ✅ `FilterBascarByPeriodStep` → SQL UPDATE
- ✅ `GenerateBascarCompositeKeyStep` → SQL UPDATE
- ✅ `GeneratePagaplCompositeKeyStep` → SQL UPDATE
- ✅ `CrossBascarWithPagaplStep` → SQL (ya optimizado)
- ✅ `RemoveCrossedBascarRecordsStep` → SQL DELETE (necesita fix)
- ✅ `LoadDettraAllSheetsStep` → Movido a LoadDettraDataSourceJob
- ✅ `CountDettraWorkersAndUpdateBascarStep` → SQL UPDATE

#### Steps NUEVOS a implementar:
- 🆕 `CrossBascarWithBaprpoStep`
- 🆕 `CrossBascarWithPagplaStep`
- 🆕 `CrossBascarWithDatpolStep`
- 🆕 `GenerateOutputFilesStep`

---

## 📝 Optimizaciones Adicionales

### 1. Composite Keys en SQL (más rápido)

**Antes (durante carga):**
```php
// Concatenar en PHP durante inserción
$data['composite_key'] = $row['NUM_TOMADOR'] . '-' . $period;
```

**Después (en SQL después de carga completa):**
```sql
-- Mucho más rápido con UPDATE masivo
UPDATE data_source_bascar
SET composite_key = num_tomador || '-' || periodo
WHERE run_id = ? AND composite_key IS NULL;

-- Crear índice concurrentemente (sin bloquear tabla)
CREATE INDEX CONCURRENTLY idx_bascar_composite_run
ON data_source_bascar (composite_key, run_id)
WHERE run_id = ?;
```

### 2. Desactivar Logs DEBUG durante carga masiva

```php
// En jobs de carga
public function handle()
{
    // Guardar nivel actual
    $originalLevel = config('logging.level');

    // Cambiar a INFO (sin DEBUG)
    config(['logging.level' => 'info']);

    try {
        // Carga masiva...
    } finally {
        // Restaurar nivel original
        config(['logging.level' => $originalLevel]);
    }
}
```

### 3. Chunk Size más grande para Excel

```php
// ANTES
$chunkSize = 5000;

// DESPUÉS (para archivos grandes)
$chunkSize = 10000; // o 15000
```

---

## 🧪 Plan de Pruebas

### Test 1: Jobs de Carga en Paralelo
1. Crear Run #9
2. Verificar que se disparan 4 jobs simultáneamente
3. Monitorear CPU/RAM de PostgreSQL
4. Verificar que todos completan exitosamente
5. Medir tiempo total de carga

**Expectativa:** ~20 minutos (vs ~40-50 actual)

### Test 2: Procesamiento SQL
1. Verificar que `ProcessCollectionDataJob` se dispara automáticamente
2. Verificar que todos los steps SQL ejecutan correctamente
3. Verificar que no hay errores de composite_key
4. Medir tiempo de procesamiento SQL

**Expectativa:** ~5 minutos

### Test 3: Run Completo End-to-End
1. Crear Run #10
2. Medir tiempo total desde creación hasta completado
3. Verificar archivos de salida generados
4. Validar datos en BD

**Expectativa:** ~25 minutos total

---

## 📋 Checklist de Implementación

### Fase 1: Crear Jobs
- [ ] `LoadCsvDataSourcesJob.php`
- [ ] `LoadPagaplDataSourceJob.php`
- [ ] `LoadDettraDataSourceJob.php`
- [ ] `LoadPagplaDataSourceJob.php`
- [ ] `ProcessCollectionDataJob.php`

### Fase 2: Configuración
- [ ] Modificar `ProcessCollectionRunValidation.php` (dispatcher)
- [ ] Configurar colas en `config/horizon.php`
- [ ] Reiniciar Horizon

### Fase 3: Refactorizar Steps
- [ ] Actualizar `ConstitucionMoraAportantesProcessor.php`
- [ ] Actualizar `ValidateDataIntegrityStep` para verificar BD
- [ ] Optimizar `GenerateBascarCompositeKeyStep` (SQL puro)
- [ ] Optimizar `GeneratePagaplCompositeKeyStep` (SQL puro)
- [ ] Fix `RemoveCrossedBascarRecordsStep` (actualizar shouldExecute)

### Fase 4: Steps Nuevos
- [ ] Implementar `CrossBascarWithBaprpoStep`
- [ ] Implementar `CrossBascarWithPagplaStep`
- [ ] Implementar `CrossBascarWithDatpolStep`
- [ ] Implementar `GenerateOutputFilesStep`

### Fase 5: Testing
- [ ] Test unitario de cada job
- [ ] Test de integración del batch
- [ ] Test end-to-end con Run completo
- [ ] Monitoreo de recursos (CPU/RAM)

---

## 🎯 Métricas de Éxito

| Métrica | Antes | Meta | Medición |
|---------|-------|------|----------|
| Tiempo de carga | ~40-50 min | ~20 min | ⏱️ Tiempo hasta ProcessCollectionDataJob |
| Tiempo total | ~50-60 min | ~25 min | ⏱️ created_at → completed_at |
| CPU PostgreSQL | 99% (bloqueado) | <60% | 📊 docker stats |
| Errores de memoria | Sí | No | ✅ Sin crashes |
| Reintentos exitosos | N/A | >80% | 📊 failed_jobs table |

---

## 📚 Referencias

**Archivos clave:**
- `app/Jobs/ProcessCollectionNoticeRunData.php` (actual, a reemplazar)
- `app/Jobs/ProcessCollectionRunValidation.php` (modificar dispatcher)
- `app/UseCases/Recaudo/Comunicados/Processors/ConstitucionMoraAportantesProcessor.php`
- `app/UseCases/Recaudo/Comunicados/Steps/CrossBascarWithPagaplStep.php` ✅ (ya optimizado)
- `config/horizon.php` (agregar colas)

**Optimizaciones aplicadas:**
- ✅ CrossBascarWithPagaplStep optimizado con tabla temporal (7.3s)
- ✅ Índices optimizados aplicados (`idx_bascar_composite_run`, `idx_pagapl_composite_run`)
- ✅ Migración de columnas trabajadores aplicada
- ✅ Logs DEBUG eliminados de ServiceProvider y CreateRunModal

**Pendiente:**
- 🔄 Implementar jobs paralelos (este documento)
- 🔄 Implementar steps faltantes (BAPRPO, PAGPLA, DATPOL)
- 🔄 Implementar generación de archivos de salida

---

**Estado:** ✅ IMPLEMENTACIÓN COMPLETA
**Próximo paso:** Crear Run de prueba para validar el sistema
**Última actualización:** 2025-10-02 23:50 UTC

---

## ✅ Implementación Completada

### Jobs Creados:
1. ✅ `app/Jobs/LoadCsvDataSourcesJob.php` - Carga CSV en paralelo
2. ✅ `app/Jobs/LoadPagaplDataSourceJob.php` - Carga PAGAPL por periodo
3. ✅ `app/Jobs/LoadDettraDataSourceJob.php` - Carga todas las hojas DETTRA
4. ✅ `app/Jobs/LoadPagplaDataSourceJob.php` - Carga PAGPLA por periodo
5. ✅ `app/Jobs/ProcessCollectionDataJob.php` - Procesamiento SQL puro

### Configuración Aplicada:
- ✅ Modificado `ProcessCollectionRunValidation.php` con `Bus::batch()`
- ✅ Configuradas nuevas colas en `config/horizon.php`:
  - `csv-loading` (1 worker, 512MB, 5min timeout)
  - `excel-loading` (3 workers, 2GB, 40min timeout)
  - `processing` (2 workers, 2GB, 30min timeout)
- ✅ Refactorizado `ConstitucionMoraAportantesProcessor` (solo SQL)
- ✅ Horizon reiniciado exitosamente

### Arquitectura Implementada:
```
Usuario crea Run
    ↓
ValidateCollectionNoticeRunJob
    ↓
Bus::batch() - 4 jobs en paralelo:
    ├─→ LoadCsvDataSourcesJob (BASCAR, BAPRPO, DATPOL)
    ├─→ LoadPagaplDataSourceJob (hoja del periodo)
    ├─→ LoadDettraDataSourceJob (todas las hojas)
    └─→ LoadPagplaDataSourceJob (hoja del periodo)
    ↓
then() → ProcessCollectionDataJob (SQL puro)
    ↓
✅ COMPLETADO
```
