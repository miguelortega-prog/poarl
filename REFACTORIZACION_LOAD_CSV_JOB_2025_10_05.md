# Refactorización LoadCsvDataSourcesJob - Patrón Consistente
**Fecha**: 2025-10-05
**Estado**: ✅ Completado - PENDIENTE DE PRUEBA

---

## 🎯 Objetivo

Refactorizar `LoadCsvDataSourcesJob` para que siga el **mismo patrón** que `LoadExcelWithCopyJob`:
- Un job por archivo (no un job para todos los archivos)
- Parametrizado con `fileId` y `dataSourceCode`
- Reutilizable para diferentes tipos de comunicados

---

## 📊 Comparación Antes/Después

### ❌ ANTES (Inconsistente)

```php
// ProcessCollectionRunValidation.php
$chain = [
    new LoadCsvDataSourcesJob($runId),              // ← Procesa TODOS los CSV (BASCAR, BAPRPO, DATPOL)
    new LoadExcelWithCopyJob($fileId, 'DETTRA'),    // ← Procesa UN archivo
    new LoadExcelWithCopyJob($fileId, 'PAGAPL'),    // ← Procesa UN archivo
    new LoadExcelWithCopyJob($fileId, 'PAGPLA'),    // ← Procesa UN archivo
    new ProcessCollectionDataJob($runId),
];

// LoadCsvDataSourcesJob.php
public function __construct(private readonly int $runId)
{
    // Itera sobre todos los archivos del run
    foreach ($run->files as $file) {
        if (in_array($dataSourceCode, ['BASCAR', 'BAPRPO', 'DATPOL'])) {
            // Procesa el archivo
        }
    }
}
```

**Problemas:**
- ❌ Inconsistencia: CSV usa un patrón, Excel otro
- ❌ Hardcoded: Data sources BASCAR, BAPRPO, DATPOL en el job
- ❌ No reutilizable: No sirve para comunicados que solo necesitan BASCAR + DATPOL
- ❌ Logs poco granulares: Difícil identificar qué archivo específico falló

---

### ✅ DESPUÉS (Consistente)

```php
// ProcessCollectionRunValidation.php
$chain = [
    new LoadCsvDataSourcesJob($fileId, 'BASCAR'),   // ← Procesa UN CSV
    new LoadCsvDataSourcesJob($fileId, 'BAPRPO'),   // ← Procesa UN CSV
    new LoadCsvDataSourcesJob($fileId, 'DATPOL'),   // ← Procesa UN CSV
    new LoadExcelWithCopyJob($fileId, 'DETTRA'),    // ← Procesa UN archivo
    new LoadExcelWithCopyJob($fileId, 'PAGAPL'),    // ← Procesa UN archivo
    new LoadExcelWithCopyJob($fileId, 'PAGPLA'),    // ← Procesa UN archivo
    new ProcessCollectionDataJob($runId),
];

// LoadCsvDataSourcesJob.php
public function __construct(
    private readonly int $fileId,           // ← ID del archivo específico
    private readonly string $dataSourceCode // ← Código del data source
)
{
    // Carga SOLO el archivo con este ID
    $file = CollectionNoticeRunFile::find($this->fileId);

    // Procesa SOLO este archivo a ESTA tabla
    $tableName = self::TABLE_MAP[$this->dataSourceCode];
}
```

**Ventajas:**
- ✅ **Consistencia**: Mismo patrón para CSV y Excel
- ✅ **Sin hardcoding**: Data sources se pasan como parámetro
- ✅ **Reutilizable**: Sirve para cualquier combinación de archivos CSV
- ✅ **Logs granulares**: Identificación exacta del archivo en proceso
- ✅ **Escalable**: Fácil agregar nuevos data sources CSV sin tocar el job

---

## 📝 Archivos Modificados

### 1. `app/Jobs/LoadCsvDataSourcesJob.php`

#### Constructor
```php
// ANTES
public function __construct(private readonly int $runId)

// DESPUÉS
public function __construct(
    private readonly int $fileId,
    private readonly string $dataSourceCode
)
```

#### Imports
```php
// ANTES
use App\Models\CollectionNoticeRun;

// DESPUÉS
use App\Models\CollectionNoticeRunFile;
```

#### Handle Method
```php
// ANTES
$run = CollectionNoticeRun::with(['files.dataSource'])->findOrFail($this->runId);

// Limpiar TODAS las tablas CSV
foreach (self::TABLE_MAP as $tableName) {
    DB::table($tableName)->where('run_id', $this->runId)->delete();
}

// Iterar sobre TODOS los archivos del run
foreach ($run->files as $file) {
    // Procesar si es CSV y está en TABLE_MAP
}

// DESPUÉS
$file = CollectionNoticeRunFile::with(['run', 'dataSource'])->find($this->fileId);
$runId = $file->collection_notice_run_id;
$tableName = self::TABLE_MAP[$this->dataSourceCode];

// Limpiar SOLO la tabla de ESTE data source
DB::table($tableName)->where('run_id', $runId)->delete();

// Procesar SOLO este archivo
$result = $importer->importFromFile($tableName, $csvPath, ...);
```

#### Logs Mejorados
```php
Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
Log::info('🚀 INICIANDO IMPORTACIÓN CSV RESILIENTE');
Log::info('📊 Data Source: ' . $this->dataSourceCode);
Log::info('📁 Archivo: ' . basename($file->path));
Log::info('💾 Tamaño: ' . round($file->size / 1024 / 1024, 2) . ' MB');
Log::info('🎯 Tabla destino: ' . $tableName);
Log::info('⚙️  Método: Resilient Line-by-Line (UTF-8 conversion)');
Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
```

#### Failed Method
```php
// ANTES
Log::error('Job de carga CSV falló definitivamente', [
    'job' => self::class,
    'run_id' => $this->runId,
    'error' => $exception->getMessage(),
]);

// DESPUÉS
Log::error('Job de carga CSV falló definitivamente', [
    'job' => self::class,
    'file_id' => $this->fileId,
    'data_source' => $this->dataSourceCode,
    'error' => $exception->getMessage(),
]);
```

---

### 2. `app/Jobs/ProcessCollectionRunValidation.php`

#### Chain Creation
```php
// ANTES (líneas 107-114)
$csvFiles = $run->files()->whereIn('ext', ['csv'])->get();

if ($csvFiles->isNotEmpty()) {
    $excelFiles = $run->files()->whereIn('ext', ['xlsx', 'xls'])->with('dataSource')->get();

    $chain = [new LoadCsvDataSourcesJob($run->id)];

    foreach ($excelFiles as $file) {
        $chain[] = new LoadExcelWithCopyJob($file->id, $file->dataSource->code);
    }

    $chain[] = new ProcessCollectionDataJob($run->id);
}

// DESPUÉS (líneas 107-125)
$csvFiles = $run->files()->whereIn('ext', ['csv'])->with('dataSource')->get();
$excelFiles = $run->files()->whereIn('ext', ['xlsx', 'xls'])->with('dataSource')->get();

if ($csvFiles->isNotEmpty() || $excelFiles->isNotEmpty()) {
    $chain = [];

    // Agregar un job por cada archivo CSV
    foreach ($csvFiles as $file) {
        $chain[] = new LoadCsvDataSourcesJob($file->id, $file->dataSource->code);
    }

    // Agregar un job por cada archivo Excel
    foreach ($excelFiles as $file) {
        $chain[] = new LoadExcelWithCopyJob($file->id, $file->dataSource->code);
    }

    $chain[] = new ProcessCollectionDataJob($run->id);
}
```

#### Log Info
```php
// ANTES
Log::info('Chain de jobs creado (ejecución SECUENCIAL)', [
    'run_id' => $run->id,
    'csv_jobs' => 1,
    'excel_jobs' => $excelFiles->count(),
    'processing_jobs' => 1,
    'total_jobs' => count($chain),
]);

// DESPUÉS
Log::info('Chain de jobs creado (ejecución SECUENCIAL)', [
    'run_id' => $run->id,
    'csv_jobs' => $csvFiles->count(),
    'excel_jobs' => $excelFiles->count(),
    'processing_jobs' => 1,
    'total_jobs' => count($chain),
]);
```

---

## 🔍 Chain Resultante

### Ejemplo con run_id=1 (Constitución en Mora - Aportantes)

```php
$chain = [
    // Jobs CSV (3 archivos)
    new LoadCsvDataSourcesJob(file_id: 1, dataSource: 'BASCAR'),
    new LoadCsvDataSourcesJob(file_id: 2, dataSource: 'BAPRPO'),
    new LoadCsvDataSourcesJob(file_id: 3, dataSource: 'DATPOL'),

    // Jobs Excel (3 archivos)
    new LoadExcelWithCopyJob(file_id: 4, dataSource: 'DETTRA'),
    new LoadExcelWithCopyJob(file_id: 5, dataSource: 'PAGAPL'),
    new LoadExcelWithCopyJob(file_id: 6, dataSource: 'PAGPLA'),

    // Job de procesamiento SQL
    new ProcessCollectionDataJob(run_id: 1),
];

// Total: 7 jobs
// - 3 CSV jobs
// - 3 Excel jobs
// - 1 processing job
```

---

## ✅ Ventajas de la Refactorización

### 1. Consistencia
- **Antes**: CSV usaba un job para todos, Excel usaba uno por archivo
- **Después**: Ambos usan el mismo patrón (un job por archivo)

### 2. Reutilización
```php
// Ejemplo: Comunicado tipo 2 que solo necesita BASCAR y DATPOL
$chain = [
    new LoadCsvDataSourcesJob($file->id, 'BASCAR'),
    new LoadCsvDataSourcesJob($file->id, 'DATPOL'),
    // No necesita BAPRPO
    new ProcessCollectionDataJob($run->id),
];

// Ejemplo: Comunicado tipo 3 con data sources diferentes
$chain = [
    new LoadCsvDataSourcesJob($file->id, 'NUEVODS1'),
    new LoadCsvDataSourcesJob($file->id, 'NUEVODS2'),
    new ProcessCollectionDataJob($run->id),
];
```

### 3. Granularidad en Logs
```
// ANTES (un log para todos los CSV)
[2025-10-05 21:46:12] local.INFO: 🎉 Carga RESILIENTE de CSV completada
{"files_loaded":3,"total_success_rows":373584}

// DESPUÉS (logs específicos por archivo)
[2025-10-05 21:46:12] local.INFO: 🎉 IMPORTACIÓN CSV RESILIENTE COMPLETADA
{"data_source":"BASCAR","total_rows":255178,"success_rows":255178}

[2025-10-05 21:50:15] local.INFO: 🎉 IMPORTACIÓN CSV RESILIENTE COMPLETADA
{"data_source":"BAPRPO","total_rows":50000,"success_rows":50000}

[2025-10-05 21:55:20] local.INFO: 🎉 IMPORTACIÓN CSV RESILIENTE COMPLETADA
{"data_source":"DATPOL","total_rows":68406,"success_rows":68406}
```

### 4. Escalabilidad
```php
// Agregar nuevo data source CSV es trivial
private const TABLE_MAP = [
    'BASCAR' => 'data_source_bascar',
    'BAPRPO' => 'data_source_baprpo',
    'DATPOL' => 'data_source_datpol',
    'NUEVODS' => 'data_source_nuevods', // ← Solo agregar aquí
];

// NO hay que modificar el handle() ni el constructor
```

### 5. Paralelización Futura
```php
// Si en el futuro se quiere paralelizar CSV (para runs pequeños)
$batch = Bus::batch([
    new LoadCsvDataSourcesJob($file1->id, 'BASCAR'),
    new LoadCsvDataSourcesJob($file2->id, 'BAPRPO'),
    new LoadCsvDataSourcesJob($file3->id, 'DATPOL'),
])->then(function (Batch $batch) {
    // Continuar con Excel jobs
})->dispatch();
```

---

## ⚠️ Consideraciones Importantes

### 1. Idempotencia Mantenida
Cada job limpia **solo su tabla** antes de insertar:
```php
DB::table($tableName)->where('run_id', $runId)->delete();
```
- ✅ Si el job se reintenta, no duplica datos
- ✅ No afecta otras tablas del mismo run
- ✅ Permite reintentos seguros

### 2. Timeout Apropiado
- Timeout: 4 horas (14400s) - suficiente para archivos grandes
- Si un CSV específico falla, solo ese job falla (no todos los CSV)

### 3. Orden de Ejecución
Chain garantiza orden secuencial:
1. Todos los CSV primero
2. Todos los Excel después
3. Procesamiento SQL al final

---

## 🧪 Testing Recomendado

### Escenarios a Probar:

1. **Run normal (6 archivos)**:
   - 3 CSV + 3 Excel → debe crear chain de 7 jobs
   - Verificar que cada job procesa solo su archivo

2. **Run con solo CSV**:
   - 3 CSV + 0 Excel → debe crear chain de 4 jobs (3 CSV + 1 processing)

3. **Run con solo Excel**:
   - 0 CSV + 3 Excel → debe crear chain de 4 jobs (3 Excel + 1 processing)

4. **Fallo de un CSV específico**:
   - Si BAPRPO falla → solo ese job falla
   - BASCAR y DATPOL deben completarse exitosamente

5. **Reintento de job CSV**:
   - Matar job a mitad de carga
   - Reintentarlo → debe limpiar tabla y reiniciar (idempotencia)

---

## 📋 Checklist de Verificación

Antes de ejecutar en producción:

- [ ] Verificar que `CollectionNoticeRunFile` model existe y tiene relación `dataSource`
- [ ] Verificar que `TABLE_MAP` tiene todos los data sources CSV necesarios
- [ ] Verificar logs de Horizon para confirmar que se crean N jobs CSV
- [ ] Verificar que cada job CSV procesa solo 1 archivo (no todos)
- [ ] Verificar idempotencia (reintento de job no duplica datos)
- [ ] Verificar que chain completo funciona (CSV → Excel → SQL)

---

**Estado**: ✅ Código refactorizado, pendiente de prueba en ambiente de desarrollo

**Próximo paso**: Ejecutar run completo y verificar logs para confirmar funcionamiento correcto
