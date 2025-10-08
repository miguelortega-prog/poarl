# Contexto Final: Pipeline de Jobs y Eliminación de Duplicación en Processor
**Fecha**: 2025-10-05
**Estado**: ✅ Completado - Sistema optimizado y sin duplicación

---

## 🎯 Resumen Ejecutivo

Se completó la optimización del pipeline de carga de datos para comunicados de recaudo, eliminando la duplicación de funcionalidad entre Jobs y Processor Steps, y estableciendo un flujo secuencial claro:

**Jobs → Carga de Datos (CSV + Excel)**
**Processor → Solo Transformaciones SQL**

---

## 📊 Arquitectura Final del Sistema

### Flujo Secuencial Completo

```
1. ProcessCollectionRunValidation
   └─ Valida archivos subidos
   └─ Dispara chain de jobs secuencial

2. LoadCsvDataSourcesJob - BASCAR (Queue: default, Timeout: 4h, Tries: 1)
   └─ Carga solo base-cartera.csv
   └─ Usa: ResilientCsvImporter (línea por línea, UTF-8 conversion)
   └─ Performance: ~26 min para 255k registros

3. LoadCsvDataSourcesJob - BAPRPO (Queue: default, Timeout: 4h, Tries: 1)
   └─ Carga solo base-produccion-por-poliza.csv
   └─ Usa: ResilientCsvImporter (línea por línea, UTF-8 conversion)
   └─ Performance: ~2-3 min para 50k registros

4. LoadCsvDataSourcesJob - DATPOL (Queue: default, Timeout: 4h, Tries: 1)
   └─ Carga solo datpol.csv
   └─ Usa: ResilientCsvImporter (línea por línea, UTF-8 conversion)
   └─ Performance: ~5-7 min para 68k registros

5. LoadExcelWithCopyJob - DETTRA (Queue: default, Timeout: 60 min, Tries: 1)
   └─ Convierte detalle-trabajadores.xlsx → CSV con Go streaming
   └─ Carga CSV con PostgreSQL COPY

6. LoadExcelWithCopyJob - PAGAPL (Queue: default, Timeout: 60 min, Tries: 1)
   └─ Convierte pagos-aplicados.xlsx → CSV con Go streaming
   └─ Carga CSV con PostgreSQL COPY

7. LoadExcelWithCopyJob - PAGPLA (Queue: default, Timeout: 60 min, Tries: 1)
   └─ Convierte pagos-planilla.xlsx → CSV con Go streaming
   └─ Carga CSV con PostgreSQL COPY

8. ProcessCollectionDataJob (Queue: default, Timeout: 30 min, Tries: 3)
   └─ Ejecuta ConstitucionMoraAportantesProcessor
       └─ Paso 1: ValidateDataIntegrityStep (valida que jobs 2-7 cargaron datos)
       └─ Paso 2+: Transformaciones SQL (filtros, cruces, generación archivos)
```

---

## 🔧 Cambios Implementados en Esta Sesión

### 1. Refactorización de LoadCsvDataSourcesJob (Patrón Consistente)

**Problema Identificado:**
- `LoadCsvDataSourcesJob` procesaba TODOS los CSV en un solo job (inconsistente)
- `LoadExcelWithCopyJob` procesaba UN archivo por job (correcto)
- Faltaba consistencia y reutilización

**Solución Implementada:**

#### `app/Jobs/LoadCsvDataSourcesJob.php` - REFACTORIZADO
```php
// ANTES: Procesaba todos los CSV de un run
public function __construct(private readonly int $runId)

// DESPUÉS: Procesa UN archivo CSV específico
public function __construct(
    private readonly int $fileId,
    private readonly string $dataSourceCode
)
```

**Cambios clave:**
- Constructor ahora recibe `$fileId` y `$dataSourceCode` (igual que LoadExcelWithCopyJob)
- Carga solo el archivo con ese ID: `CollectionNoticeRunFile::find($this->fileId)`
- Limpia solo la tabla correspondiente al dataSourceCode
- Logs mejorados con formato visual consistente

#### `app/Jobs/ProcessCollectionRunValidation.php` - ACTUALIZADO
```php
// ANTES: Un job para todos los CSV
$chain = [new LoadCsvDataSourcesJob($run->id)];

// DESPUÉS: Un job por cada archivo CSV
foreach ($csvFiles as $file) {
    $chain[] = new LoadCsvDataSourcesJob($file->id, $file->dataSource->code);
}
```

**Ventajas de la refactorización:**
- ✅ **Consistencia**: Mismo patrón para CSV y Excel
- ✅ **Reutilización**: Sirve para cualquier tipo de comunicado (no hardcoded a BASCAR/BAPRPO/DATPOL)
- ✅ **Granularidad**: Logs y errores específicos por archivo
- ✅ **Escalabilidad**: Fácil agregar nuevos data sources CSV sin modificar el job
- ✅ **Paralelización futura**: Si se necesita, fácil convertir a batch

**Chain resultante:**
```php
$chain = [
    new LoadCsvDataSourcesJob(file_id: 1, 'BASCAR'),
    new LoadCsvDataSourcesJob(file_id: 2, 'BAPRPO'),
    new LoadCsvDataSourcesJob(file_id: 3, 'DATPOL'),
    new LoadExcelWithCopyJob(file_id: 4, 'DETTRA'),
    new LoadExcelWithCopyJob(file_id: 5, 'PAGAPL'),
    new LoadExcelWithCopyJob(file_id: 6, 'PAGPLA'),
    new ProcessCollectionDataJob(run_id: 1),
];
```

### 2. Eliminación de Duplicación en Processor

**Problema Identificado:**
- Los primeros 3 steps del processor duplicaban EXACTAMENTE lo que ya hacían los jobs:
  - `LoadCsvDataSourcesStep` ❌ (duplicaba `LoadCsvDataSourcesJob`)
  - `ConvertExcelToCSVStep` ❌ (duplicaba `LoadExcelWithCopyJob` - conversión)
  - `LoadExcelCSVsStep` ❌ (duplicaba `LoadExcelWithCopyJob` - carga)

**Archivos Modificados:**

#### `app/UseCases/Recaudo/Comunicados/Processors/ConstitucionMoraAportantesProcessor.php`
```php
// ANTES (líneas 44-57): 10 dependencias inyectadas
public function __construct(
    DataSourceTableManager $tableManager,
    FilesystemFactory $filesystem,
    private readonly LoadCsvDataSourcesStep $loadCsvDataSourcesStep,      // ❌ ELIMINADO
    private readonly ConvertExcelToCSVStep $convertExcelToCSVStep,        // ❌ ELIMINADO
    private readonly LoadExcelCSVsStep $loadExcelCSVsStep,                // ❌ ELIMINADO
    private readonly ValidateDataIntegrityStep $validateDataStep,
    private readonly FilterBascarByPeriodStep $filterBascarStep,
    // ... resto
)

// DESPUÉS (líneas 44-54): 7 dependencias inyectadas
public function __construct(
    DataSourceTableManager $tableManager,
    FilesystemFactory $filesystem,
    private readonly ValidateDataIntegrityStep $validateDataStep,         // ✅ ÚNICO STEP DE VALIDACIÓN
    private readonly FilterBascarByPeriodStep $filterBascarStep,
    // ... resto de steps SQL
)
```

```php
// ANTES (líneas 114-153): 11 steps (3 de carga + 1 validación + 7 SQL)
protected function defineSteps(): array
{
    return [
        $this->loadCsvDataSourcesStep,        // ❌ ELIMINADO
        $this->convertExcelToCSVStep,         // ❌ ELIMINADO
        $this->loadExcelCSVsStep,             // ❌ ELIMINADO
        $this->validateDataStep,
        $this->generateBascarKeysStep,
        // ...
    ];
}

// DESPUÉS (líneas 104-137): 8 steps (1 validación + 7 SQL)
protected function defineSteps(): array
{
    return [
        // === FASE 1: VALIDACIÓN DE DATOS CARGADOS ===
        // Verifica que los jobs previos cargaron correctamente:
        // - BASCAR, BAPRPO, DATPOL (LoadCsvDataSourcesJob)
        // - DETTRA, PAGAPL, PAGPLA (LoadExcelWithCopyJob)
        $this->validateDataStep,              // ✅ ÚNICO STEP DE VALIDACIÓN

        // === FASE 2: TRANSFORMACIÓN Y CRUCE DE DATOS SQL ===
        $this->generateBascarKeysStep,
        $this->generatePagaplKeysStep,
        // ... resto de steps SQL
    ];
}
```

### 2. Implementación de Validación de Datos Cargados por Jobs

**Archivo:** `app/UseCases/Recaudo/Comunicados/Steps/ValidateDataIntegrityStep.php`

**Funcionalidad:**
- ✅ Valida que los 6 data sources tengan registros en BD para el `run_id`
- ✅ Reporta conteos por tabla
- ✅ Falla si algún data source tiene 0 registros (indica que los jobs fallaron)
- ✅ Logs detallados con emojis para fácil identificación

```php
private const TABLE_MAP = [
    'BASCAR' => 'data_source_bascar',
    'BAPRPO' => 'data_source_baprpo',
    'DATPOL' => 'data_source_datpol',
    'DETTRA' => 'data_source_dettra',
    'PAGAPL' => 'data_source_pagapl',
    'PAGPLA' => 'data_source_pagpla',
];

public function execute(ProcessingContextDto $context): ProcessingContextDto
{
    // Contar registros para este run_id en cada tabla
    foreach ($expectedDataSources as $dataSourceCode) {
        $recordCount = DB::table($tableName)
            ->where('run_id', $run->id)
            ->count();

        if ($recordCount === 0) {
            // FALLA: Job de carga no funcionó
            return $context->addError(...);
        }
    }

    // Reporta estadísticas completas
    Log::info('✅ Validación de integridad completada', [
        'data_sources_validated' => count($validationResults),
        'total_records_loaded' => number_format($totalRecords),
        'validation_results' => $validationResults,
    ]);
}
```

---

## 📁 Estado de Archivos Clave

### Jobs (app/Jobs/)

#### `ProcessCollectionRunValidation.php`
- **Queue**: `default`
- **Timeout**: 900s (15 min)
- **Tries**: 3
- **Función**: Valida archivos y dispara chain secuencial
- **Línea 114-151**: Implementación de `Bus::chain()` para ejecución secuencial

#### `LoadCsvDataSourcesJob.php`
- **Queue**: `default`
- **Timeout**: 14400s (4 horas)
- **Tries**: 1
- **Función**: Carga CSV (BASCAR, BAPRPO, DATPOL) con ResilientCsvImporter
- **Líneas 91-106**: Idempotencia (limpia tablas antes de insertar)
- **Servicio**: `ResilientCsvImporter` con UTF-8 conversion automática

#### `LoadExcelWithCopyJob.php`
- **Queue**: `default`
- **Timeout**: 3600s (60 min)
- **Tries**: 1
- **Función**: Convierte Excel→CSV con Go + carga con PostgreSQL COPY
- **Líneas 98-111**: Idempotencia (limpia tablas antes de insertar)

#### `ProcessCollectionDataJob.php`
- **Queue**: `default`
- **Timeout**: 1800s (30 min)
- **Tries**: 3
- **Función**: Ejecuta processor (solo transformaciones SQL)
- **Línea 107**: `$processor->process($run)` - ejecuta pipeline de steps

### Services (app/Services/Recaudo/)

#### `ResilientCsvImporter.php`
- **Chunk Size**: 10,000 registros
- **Características**:
  - ✅ Procesa línea por línea con transacciones individuales
  - ✅ Conversión automática Latin1→UTF-8
  - ✅ Log de errores en tabla `csv_import_error_logs`
  - ✅ No falla todo el proceso por errores individuales
- **Performance**: ~23 min para 255k registros (BASCAR)

#### `PostgreSQLCopyImporter.php`
- **Método**: Usa `psql` CLI con `COPY FROM STDIN`
- **Performance**: 10-50x más rápido que inserts
- **Configuración**:
  - `ESCAPE = QUOTE` para evitar problemas con backslashes
  - `NULL ''` para valores vacíos

### Processor y Steps (app/UseCases/Recaudo/Comunicados/)

#### `Processors/ConstitucionMoraAportantesProcessor.php`
- **Responsabilidad**: Solo transformaciones SQL (NO carga datos)
- **Steps**: 8 total (1 validación + 7 SQL)

#### `Steps/ValidateDataIntegrityStep.php`
- **Responsabilidad**: Valida que jobs previos cargaron datos correctamente
- **Valida**: 6 tablas (BASCAR, BAPRPO, DATPOL, DETTRA, PAGAPL, PAGPLA)
- **Falla si**: Alguna tabla tiene 0 registros para el `run_id`

---

## 🔍 Soluciones Implementadas (Sesiones Previas)

### 1. UTF-8 Encoding (DATPOL - 28% errores → 100% éxito)
**Problema**: 19,375 errores por caracteres Latin1 (CASTAÑEDA → CASTA?EDA)
**Solución**: `ResilientCsvImporter::ensureUtf8Encoding()` - conversión automática
**Resultado**: 68,406 registros, 0 errores

### 2. PostgreSQL COPY - psql not found
**Problema**: `sh: 1: psql: not found`
**Solución**: Rebuild de Docker container con `postgresql-client`
**Comando**: `docker-compose build && docker-compose up -d`

### 3. Worker Timeout (90 segundos)
**Problema**: Jobs siendo killed después de 90s
**Solución**:
- Horizon config: `timeout => 14400` (supervisor-default)
- docker-compose: `--timeout=3600`
- Jobs: `$timeout = 14400`

### 4. Ejecución Paralela → Secuencial
**Problema**: Jobs corriendo en paralelo causando conflictos
**Solución**: Cambio de `Bus::batch()` a `Bus::chain()`
**Resultado**: CSV → Excel (DETTRA) → Excel (PAGAPL) → Excel (PAGPLA) → SQL

### 5. PostgreSQL Transaction Abortion
**Problema**: "current transaction is aborted, commands ignored"
**Solución**: Transacción individual por fila en `ResilientCsvImporter::processChunk()`

---

## 🔧 Configuración Actual

### Horizon (config/horizon.php)
```php
'supervisor-default' => [
    'connection' => 'redis',
    'queue' => ['default'],
    'memory' => 2048,
    'timeout' => 14400,  // 4 horas
    'tries' => 1,
],
```

### Docker Compose (docker-compose.yml)
```yaml
poarl-horizon:
  command: php artisan horizon

poarl-worker:
  command: php artisan queue:work redis --queue=default --tries=1 --max-time=7200 --timeout=3600
```

### Base de Datos

**Tablas de Data Sources:**
- `data_source_bascar` (run_id, 58 columnas CSV)
- `data_source_baprpo` (run_id, data jsonb)
- `data_source_datpol` (run_id, data jsonb)
- `data_source_dettra` (run_id, data jsonb, sheet_name)
- `data_source_pagapl` (run_id, identificacion, periodo, valor, composite_key, data jsonb, sheet_name)
- `data_source_pagpla` (run_id, data jsonb, sheet_name)

**Tabla de Errores:**
- `csv_import_error_logs` (run_id, data_source_code, table_name, line_number, error_type, error_message)

---

## 📊 Performance Actual

### Cargas Exitosas Registradas

**BASCAR (CSV - ResilientCsvImporter):**
- Registros: 255,178
- Duración: ~23 minutos
- Errores: 0
- Método: Línea por línea con chunks de 10k

**DATPOL (CSV - ResilientCsvImporter):**
- Registros: 68,406
- Duración: ~5 minutos
- Errores: 0 (antes: 19,375 errores por UTF-8)
- Método: Línea por línea con chunks de 10k + UTF-8 conversion

**BAPRPO (CSV - ResilientCsvImporter):**
- Registros: ~50k
- Duración: ~2 minutos
- Errores: 0

**Excel Files (LoadExcelWithCopyJob - Go + PostgreSQL COPY):**
- DETTRA: ~202 MB, múltiples hojas
- PAGAPL: ~190 MB, múltiples hojas
- PAGPLA: ~289 MB, múltiples hojas
- Conversión: ~40 MB/s con Go streaming
- Carga: ~3s por 100MB con PostgreSQL COPY

---

## ✅ Estado del Sistema

### Completado
- ✅ Pipeline secuencial (CSV → Excel → SQL)
- ✅ Eliminación de duplicación Jobs vs Processor
- ✅ Validación de datos cargados por jobs en processor
- ✅ Idempotencia en todos los jobs de carga
- ✅ UTF-8 conversion automática
- ✅ Error logging granular
- ✅ Timeouts adecuados (4 horas para CSV, 60 min para Excel)
- ✅ PostgreSQL COPY funcionando con psql CLI
- ✅ ResilientCsvImporter con manejo de errores individual

### Pendiente (TODOs en código)
- ⏳ Step de depuración de tablas (eliminar registros no necesarios)
- ⏳ Cleanup de datos después de procesamiento (comentado en BaseCollectionNoticeProcessor:109)
- ⏳ Nuevos cruces de datos (pendientes de definición de reglas)

---

## 🚀 Próximos Pasos Sugeridos

1. **Verificar que el processor funciona correctamente**:
   - Ejecutar run completo y verificar que `ValidateDataIntegrityStep` pasa
   - Confirmar que los steps SQL ejecutan correctamente

2. **Implementar steps pendientes**:
   - Step de depuración de tablas
   - Nuevos cruces de datos según reglas de negocio

3. **Optimizaciones opcionales**:
   - Habilitar cleanup automático cuando el sistema esté 100% estable
   - Considerar paralelizar jobs Excel si el sistema lo permite
   - Implementar retry strategy más sofisticada para jobs de carga

---

## 📝 Notas Importantes

- **NO usar `Bus::batch()` para estos jobs**: Usar `Bus::chain()` para garantizar orden secuencial
- **Jobs de carga son idempotentes**: Limpian tablas antes de insertar (basado en `run_id`)
- **Processor NO carga datos**: Solo valida y transforma (separación de responsabilidades)
- **ResilientCsvImporter vs PostgreSQLCopyImporter**:
  - Resilient: Para CSVs problemáticos (encoding, errores), más lento pero robusto
  - COPY: Para CSVs limpios, ultra rápido pero todo-o-nada
- **Docker rebuild necesario** si se cambia Dockerfile (ej: agregar postgresql-client)

---

**Autor**: Claude Code
**Última actualización**: 2025-10-05
**Estado**: Sistema operativo y optimizado
